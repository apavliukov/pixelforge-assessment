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

On start the container runs migrations, caches the configuration, and starts two queue workers
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
| `PIXELFORGE_API_KEY`        | API key                                     |
| `PIXELFORGE_WEBHOOK_SECRET` | `whsec_…` signing secret                    |

Health check path: `/up`.

## What I would do next

Things left out on purpose to keep the assessment small, in the order I would take them on a
production project.

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
