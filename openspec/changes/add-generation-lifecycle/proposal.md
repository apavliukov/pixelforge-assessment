## Why

Pricing can already turn tokens into exact money, but nothing yet creates a generation, sends it to
Pixelforge or records what it cost. Pixelforge is asynchronous and reports the same generation
through three channels (the create response, webhooks, polling) in any order and more than once, so
the part that records state and money has to be built and proven before any page is put on top of it.

## What Changes

- Store generations: who submitted, the request, the estimate and markup agreed at submission, the status, and — once finished — usage, rates, provider cost and user charge.
- Submit a generation: record it as waiting with its estimate, refuse a fourth unfinished generation per user, refuse when pricing is unavailable, and send it to Pixelforge from the queue.
- Send with an idempotency key equal to the local identifier, so a repeated send never creates a second provider generation.
- Handle every create outcome: accepted, busy slots and rate limit (wait and retry, up to 10 minutes), overloaded and network errors (five retries with growing delays), content policy, daily limit, and rejections caused by our own bug.
- Apply provider state through one action used by all three channels: status only moves forward, money is recorded exactly once on success, and a failure decided locally gives way to whatever the provider later reports.
- Receive signed webhooks: verify the signature on the raw body, store each event once, answer immediately, apply it from the queue.
- Poll unfinished accepted generations on a schedule as a safety net, and fail generations left waiting with no job to send them.
- Extend the Pixelforge client with `POST /v1/generations` and `GET /v1/generations/{id}`.

Out of scope: pages, forms, the HTTP endpoint that submits a generation and its input validation
(the UI change), and the operator report.

## Capabilities

### New Capabilities

- `generation-submission`: creating a generation with its estimate, the per-user limit, sending it to the provider, and what each create outcome leads to.
- `generation-state`: applying provider state from any channel, recording money once, local failures, and polling.
- `provider-webhooks`: receiving, authenticating, de-duplicating and applying provider webhook events.

### Modified Capabilities

None. The `pricing` requirements are used as they are.

## Impact

- New tables: `generations`, `webhook_events`.
- New code: `Generation` and `WebhookEvent` models with factories, a status enum, a provider generation DTO, three actions, two jobs, one console command, a webhook controller with a signature middleware.
- `PixelforgeClient` gains two endpoints; `TokenUsage` gains `fromArray`.
- New route file `routes/api.php` with one route, `POST /api/webhooks/pixelforge`, registered in `bootstrap/app.php`.
- New configuration: `services.pixelforge.webhook_secret` (`PIXELFORGE_WEBHOOK_SECRET`, already in `.env.example`).
- `routes/console.php` gains the polling command, every minute.
- New base folder `app/Casts` (one Eloquent cast for `Money`).
- Two queues (`submissions`, `webhooks`) and a second worker process in `docker/production/start.sh`; the local dev listener takes both.
- No frontend changes, no new dependencies.
