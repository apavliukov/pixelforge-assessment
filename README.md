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
