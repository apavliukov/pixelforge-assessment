## Context

The pricing change left a calculator, a pricing catalog and a Pixelforge client with one endpoint.
There are no domain tables yet. `docs/DECISIONS.md` ("Generation lifecycle", "Webhooks") and
`GLOSSARY.md` record the product-level choices; this document covers how the code is laid out and
the choices that are only about implementation.

Constraints that shape the design: the queue, cache and sessions live in Postgres; production runs
the queue workers and the scheduler in the same container as the web server; Pixelforge allows 60 requests a minute,
3 concurrent generations and 500 a day for the whole app.

## Goals / Non-Goals

**Goals:**

- One place that applies provider state, safe to call any number of times in any order.
- Every write to a generation goes through an action that locks the row.
- Everything provable by tests on real Postgres with `Http::fake()` and no real provider calls.

**Non-Goals:**

- No controller, form request or page for submitting; input validation (prompt length, reference image URLs) belongs to the UI change, at the HTTP boundary.
- No request/response log table: `generations` holds the request and the result, `webhook_events` holds raw event bodies.
- No command to replay webhook events: `queue:retry` and polling cover it.
- No automatic repair when both the create response and every webhook delivery are lost (see Risks).

## Decisions

**Layout.**

| Path                                                       | Contents                                                       |
| ---------------------------------------------------------- | -------------------------------------------------------------- |
| `app/Models/Generation.php`, `WebhookEvent.php`            | models, with factories                                         |
| `app/Enums/GenerationStatus.php`                           | five statuses, `isFinal()`, `isAheadOf()`; the rank is private |
| `app/Enums/FailureCode.php`                                | codes this app assigns itself                                  |
| `app/Casts/MoneyCast.php`                                  | `bigint` column ↔ `Money`                                      |
| `app/Dto/Pixelforge/GenerationData.php`                    | the provider's generation object, validated in `fromArray`     |
| `app/Dto/Generations/SubmitGenerationInput.php`            | user, prompt, size, quality, reference image URLs              |
| `app/Actions/SubmitGeneration.php`                         | creates the waiting generation and queues the send             |
| `app/Actions/ApplyProviderState.php`                       | the single place provider state is applied                     |
| `app/Actions/FailGenerationLocally.php`                    | records a local failure, only on a waiting generation          |
| `app/Jobs/SubmitGenerationJob.php`                         | one send attempt and the decision what to do with the answer   |
| `app/Jobs/ApplyWebhookEventJob.php`                        | applies one stored event                                       |
| `app/Console/Commands/PollGenerationsCommand.php`          | `generations:poll`                                             |
| `app/Http/Controllers/PixelforgeWebhookController.php`     | stores the event and queues the job                            |
| `app/Http/Middleware/VerifyPixelforgeSignature.php`        | signature and timestamp check on the raw body                  |
| `app/Exceptions/TooManyUnfinishedGenerationsException.php` | thrown by `SubmitGeneration`                                   |

`app/Casts` is a new base folder (Laravel's own location for `make:cast`). _Alternative:_ four
accessor/mutator pairs on the model — more code for the same thing.

**`generations` table.** `id` UUID (the model's `HasUuids`, time-ordered), `user_id`,
`provider_id` nullable unique, `prompt` text, `size`, `quality`, `reference_images` jsonb, `status`,
`price_multiplier_basis_points`, `estimate_min` and `estimate_max` bigint, four nullable token counters, `rates` jsonb
(the provider's strings as received), `provider_cost` and `user_charge` nullable bigint,
`error_code`, `error_message`, `image_url`, `image_url_expires_at`, `provider_synced_at`,
`completed_at`, timestamps. Indexes: `(user_id, created_at)`, `status`.

- Costs are `null` while unfinished and `0` on any failure, so "not known yet" and "free" differ.
- `created_at` is the submission time; the brief's separate `submitted_at` would duplicate it.
- `provider_synced_at` is the last time we heard from or asked the provider about the generation. Polling selects and orders by it.
- `error_code` is a plain string: the provider's codes are an open set. `FailureCode` names the codes recorded before the provider accepted a generation (`content_policy_violation`, `wait_timeout`, `provider_unavailable`, `daily_limit_exceeded`, `submission_rejected`).
- There is no "charged above the maximum" column: `user_charge > estimate_max` says it.

**`webhook_events` table.** `id`, `event_id` unique, `type`, `payload` text (the raw body, not
re-encoded JSON), `processed_at` nullable, `created_at`.

**`ApplyProviderState` takes a `GenerationData` and returns the generation or `null`.** Inside one
transaction it finds the row by `provider_id`, else by `metadata.local_id`, with `lockForUpdate`,
then:

1. Fills `provider_id` and `rates` if empty and stamps `provider_synced_at` — always.
2. Stops unless the incoming status `isAheadOf()` the current one, or the row is a local failure (`failed` with no `provider_id` before step 1).
3. For a local failure, clears the error and the zero costs and takes the incoming status as is.
4. On `succeeded`: stores usage, costs via `PriceCalculator`, image URL and expiry, `completed_at`. On `failed`: stores the error, zero costs, `completed_at`.

`GenerationData::fromArray` rejects a `succeeded` object without `usage` or `rates`, so step 4
cannot record a success without money. A different final status after a provider final is logged
and ignored. The rank lives only in the enum; the local-failure exception lives only here, because
it is a rule about a generation, not about a status.

**`SubmitGeneration` checks the limit under a lock on the user row.** Two simultaneous submissions
by one user would otherwise both count two unfinished generations. Pricing is read before the
transaction; the generation row and the queued job are written inside it, which is atomic because
the queue is in the same database.

**The send job re-dispatches itself instead of using `release()`.** `SubmitGenerationJob` runs with
one try and carries its own count of temporary failures. After a `429` it dispatches a copy with the
`Retry-After` delay (default 5 s, clamped to 1–60 s); after a `5xx` or a connection error it
dispatches a copy with the count increased and the 2/4/8/16/32 s delay. `release()` has a single
attempts counter, which cannot tell "waited for a slot 40 times" from "provider failed 5 times".
The 10-minute wait limit is checked against `created_at` at the start of each attempt. An unexpected exception is caught in `handle()`, which
records `submission_rejected` and rethrows, so the generation is not left waiting. Not the
`failed()` hook: the queue calls it without the container, so the action could not be injected; a
worker that dies mid-job is covered by polling's 15-minute rule instead.

The client calls `->throw()`; the job reads the status, `error.code` and `Retry-After` from the
`RequestException`'s response. No exception class per provider error.

**The webhook route lives in `routes/api.php`** (`POST /api/webhooks/pixelforge`), registered through
`withRouting(api: …)`. The `api` group has no session and no CSRF, so nothing has to be excluded and
webhook calls do not create session rows. Sanctum is not installed.

**Duplicates are detected with `createOrFirst`.** It inserts inside a savepoint, so the unique
violation of a repeated `event_id` does not abort the surrounding transaction in Postgres; it then
returns the stored row, and `wasRecentlyCreated` tells the controller whether to dispatch.

**An empty webhook secret rejects every request.** An HMAC with an empty key can be computed by
anyone.

**Two queues, two worker processes.** Send jobs go to the `submissions` queue; webhook event jobs
go to `webhooks`. Production starts one `queue:work` process per queue (the `webhooks` worker also
takes `default`, where the pricing refresh runs). A worker handles one job at a time, and a send is
an HTTP call that can take up to 10 seconds when the provider is slow; with a shared worker a burst
of sends would delay recording results that have already arrived. Locally one listener takes all
three queues.
_Alternative:_ one worker with `--queue=webhooks,submissions` — priority, but an event still waits
for the send in progress.

**Polling is a scheduled console command, as the brief asks, not a scheduled queued job.** Both are
started by the scheduler every minute; the difference is who does the work. `Schedule::job` only
puts a job on a queue, where a worker runs it in turn; `Schedule::command` is executed by the
scheduler's own process, at once. A polling run makes up to 20 HTTP calls, so as a job it would
hold back whichever queue it was put on. As a command it runs beside the workers and can also be
run by hand (`generations:poll`). It is scheduled with `withoutOverlapping()`.

The two selections are model scopes (`dueForPolling`, `abandonedWhileWaiting`), so the command stays a trigger. One run: fail generations waiting over 15 minutes; then take up to 20 accepted unfinished
generations whose `provider_synced_at` is older than 1 minute (submitted within 10 minutes) or
10 minutes (older), least recently checked first; fetch each and apply it. A failed fetch still
stamps `provider_synced_at`, so one broken generation cannot keep the head of the list; a `429`
ends the run. 20 requests a minute leaves 40 of the provider's 60 for sends.

**Timings are class constants, not configuration.** Nothing changes them per environment.

**Tests.** Feature tests on Postgres with factories and `Http::fake()` / `Queue::fake()`. Provider
generation objects come from one test helper that builds the documented object with overrides.
Orderings are tested by applying states one after another; there is no test with two concurrent
processes, so the row lock is covered by reading the code, not by a test.

## Risks / Trade-offs

- [The create response and every webhook delivery are lost] → The generation stays failed locally while the provider billed it. Re-sending with the same key could repair it, but would create a new, paid generation if the first was never accepted, after the user was told "not charged". Left unrepaired; the operator report shows the difference against `GET /v1/usage`.
- [The worker dies during a send] → The generation stays waiting with no job. The polling command fails it after 15 minutes; a webhook, if the provider did accept it, still wins.
- [A user sees "failed" become "succeeded" with a charge] → Only when a local failure is overridden by the provider. Accepted: the money must match the provider.
- [No concurrency test] → Idempotent application and the unique indexes make a double apply harmless: both writers would store the same values. The lock is insurance, stated as such in the README.
- [The error body shape and `Retry-After` on `rate_limit_exceeded` are taken from the docs] → Confirmed against the real API with the `[blocked]` and `[overloaded]` keywords in the last task, using the local key.
- [Two workers, the scheduler and the web server share a 512 MB container] → Memory is checked on the first deploy. If it does not fit, the fallback is the one-worker alternative above: a one-line change in the start script.
- [Stored `webhook_events` grow without bound] → Irrelevant at 500 generations a day; pruning is not built.
