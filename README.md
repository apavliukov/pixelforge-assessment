# Pixelforge assessment

A Laravel + Inertia + Vue app that generates images through the Pixelforge API and accounts for
their cost exactly.

The reasoning behind the open choices is in [docs/DECISIONS.md](docs/DECISIONS.md).

## Local and production at a glance

|            | Local                         | Production                                              |
| ---------- | ----------------------------- | ------------------------------------------------------- |
| Defined by | `compose.yaml` (Laravel Sail) | `docker/production/`                                    |
| Runs       | app container + Postgres      | one container: web server, two queue workers, scheduler |
| Database   | Postgres in Sail              | managed Postgres (Neon)                                 |
| Started by | `make local-dev`              | the hosting platform, from the `Dockerfile`             |

## Local development

Requires Docker, PHP 8.4 with Composer (only to install Sail itself) and Node.

```sh
cp .env.example .env          # then fill in PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET
composer install
npm install                   # also installs the git hooks (lefthook.yml)
make local-up                 # start the containers
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate
vendor/bin/sail artisan db:seed  # creates the demo accounts; safe to repeat
make local-dev                # Vite, queue listener, scheduler and logs in one terminal
```

The app is served on `http://localhost` (set `APP_PORT` in `.env` to change the port).

Pixelforge delivers results by webhook, so it must be able to reach the app. Set
`DEV_TUNNEL_COMMAND` in `.env` to the command of any tunnel tool and `make local-dev` starts it
alongside the rest. Without a tunnel the app still works: unfinished generations are polled once a
minute.

The pre-commit hook formats staged files with `vp check`, Rector and Pint; the PHP tools run in
Sail, so the containers must be up. The same checks run in CI (`composer ci:check`).

Tests run against the `testing` database in the same Postgres container:

```sh
vendor/bin/sail artisan test
```

## Production

Production is a single Docker image, `docker/production/Dockerfile`, built from the repository root.
The hosting platform builds and runs it; nothing in the `Makefile` is involved.

On start the container runs migrations and the seeder, caches the configuration, and starts two queue workers
(`submissions`; `webhooks` and `default`), the scheduler and the web server
(`docker/production/start.sh`). It listens on `$PORT`.

Environment variables the host must provide:

| Variable                    | Value                                       |
| --------------------------- | ------------------------------------------- |
| `APP_KEY`                   | output of `php artisan key:generate --show` |
| `APP_ENV`                   | `production`                                |
| `APP_DEBUG`                 | `false`                                     |
| `APP_URL`                   | public URL of the app                       |
| `DB_CONNECTION`             | `pgsql`                                     |
| `DB_URL`                    | Postgres connection string                  |
| `LOG_CHANNEL`               | `stderr`                                    |
| `DEMO_LOGIN_ENABLED`        | `true` on the public demo, see below        |
| `PIXELFORGE_API_KEY`        | API key                                     |
| `PIXELFORGE_WEBHOOK_SECRET` | `whsec_…` signing secret                    |

Health check path: `/up`.

### Demo accounts

The seeder creates three accounts: two demo users, Ada and Bob, and a demo operator. Their passwords
are random and recorded nowhere. With `DEMO_LOGIN_ENABLED=true` the sign-in page shows a button for
each, and anyone can sign in as one of them without a password: the operator lands on the operator
report, the users on the generation page. Registration stays open for anyone who wants an account
of their own.

This makes the public demo open on purpose. Anyone can spend the provider key's quota and read the
operator report, which holds the demo accounts and whoever chose to register. Leave the variable
unset anywhere real users or real money are involved: the buttons disappear and the route behind
them answers 404. Only `true`, `1`, `on` and `yes` switch it on.

The seeder creates accounts only. It never creates generations: an invented one would carry a
provider cost that Pixelforge knows nothing about, and the report could not reconcile.

## Checking the behaviour

Pixelforge reacts to keywords anywhere in the prompt, which makes each path easy to see:

| Keyword        | What happens                                                            |
| -------------- | ----------------------------------------------------------------------- |
| `[succeed]`    | accepted and never fails at random                                      |
| `[slow]`       | takes 60–90 seconds: the history shows the status changing live         |
| `[fail]`       | accepted, then fails while processing: shown as failed, charged `$0.00` |
| `[blocked]`    | refused by the content policy at submission: failed, not charged        |
| `[overloaded]` | `503` at submission: retried five times, then failed, not charged       |
| `[duplicate]`  | every webhook event is delivered twice: the cost is still recorded once |

The operator report (`/operator/report`, demo operator) lists user charges, provider costs and
profit per UTC day and user, and compares the provider costs with Pixelforge's own `GET /v1/usage`.

Not built: credit balances, the second stretch goal. The reason is in
[docs/DECISIONS.md](docs/DECISIONS.md#scope).

## What I would do next

Things left out on purpose to keep the assessment small, in the order I would take them on a
production project.

### Replace the operator flag with roles and permissions

An operator is a user with `is_operator` set, and only the seeder sets it. That is a shortcut taken
to keep the assessment small, and not something I would keep: a boolean column answers one question
for one kind of privileged user, cannot say who granted it or when, and has no way to be managed
from the app.

The fix is role-based access control, for example `spatie/laravel-permission`: an `operator` role
holding a `view operator report` permission, assigned and revoked through the app with a record of
who did it. Authorization already goes through one gate, `viewOperatorReport`, so the change is the
body of that gate and the seeder, and nothing that calls them.

### Generate the frontend types from the backend

The shape of a generation is written twice: `GenerationResource` in PHP and
`resources/js/types/generations.ts` in TypeScript. Only the shape is duplicated — every amount,
status text and failure reason is produced by the backend — and Pest tests pin the PHP side, but
nothing checks that the TypeScript side still matches it.

The fix is to describe the data once and generate the rest: turn the resource into a
`spatie/laravel-data` object with typed properties and let `spatie/typescript-transformer` emit the
TypeScript types, enums included, as part of the build. For one resource of 14 fields and three
enums, two dependencies cost more than the risk they remove; with a second or third resource they
pay for themselves.

### Remember that pricing is unavailable

Pricing is cached for an hour and refreshed by a job every five minutes. When the copy has expired
and Pixelforge is still down, `PricingCatalog::current()` tries to fetch it again on every page load
and every estimate request, and each attempt can hold a PHP worker until the HTTP timeout. A few
users typing in the form during a long outage could occupy every worker.

The fix is to cache the failure for 30–60 seconds, so that during an outage the page answers
"pricing unavailable" at once and only the scheduled job keeps asking Pixelforge.

### Say which generations a mismatch is about

The operator report reconciles provider costs per day: it shows that a day differs and by how much,
not which generation is missing or extra. Finding it means comparing our rows with the provider's
list of generations by hand.

The fix is a drill-down for a day that differs: fetch the provider's generations completed that day
and list the ones we have no succeeded row for, and the reverse.
