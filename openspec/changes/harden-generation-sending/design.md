## Context

`SendGeneration` makes one send attempt and returns a `SendRetry` when the generation must be sent
again; `SendGenerationJob` then queues a delayed copy of itself. `FailGenerationLocally` records a
failure the app decided itself, without a provider identifier. `ApplyProviderState` already replaces
such a failure with any provider state that arrives for the generation, found by
`metadata.local_id` — but only a webhook could bring one, because `PollDueGenerations` asks about
generations by provider identifier. The provider's `GET /v1/generations` returns its generations
newest first, 100 at most per page, each with `metadata.local_id` and a `next_cursor`.

`provider_synced_at` on a generation is "the last time the provider reported on it or was asked".
A local failure has none.

## Goals / Non-Goals

**Goals:**

- No path on which a provider cost can exist without a succeeded generation here, short of the provider itself being unreachable for a day.
- One waiting generation costs about one request a minute once it has waited a minute.
- A restart delays a send by the queue's retry time, not by the sweep.

**Non-Goals:**

- A second attempts counter, a new column, or a change to the limits (10 minutes, 20 requests a run, five retries).

## Decisions

### Checking local failures against the provider's list

`Generation::unverifiedLocalFailures()` is a scope: status failed, no provider identifier, error
code one of `provider_unavailable`, `wait_timeout`, `submission_rejected`, completed in the last 24
hours but not in the last two minutes, and `provider_synced_at` null.

The two minutes matter because the check is final. A send is given up on after 10 seconds, but the
request may still reach the provider afterwards; a list read in the same minute could miss the
generation, mark the failure as checked, and hide a billed generation for good.

`PollDueGenerations` gets a step between the sweep of abandoned waits and the polling:

1. Load the unverified local failures. None → no request.
2. Ask `PixelforgeClient::generations(?string $cursor)` for a page. It returns the page's generations by local identifier, as received: only the ones that are ours are validated, so one malformed generation elsewhere on the page cannot block the check. For each of ours, call `ApplyProviderState`, which makes the generation whatever the provider says and sets `provider_synced_at`. `ApplyProviderState` writes a notice to the log whenever it replaces a local failure, whichever way the state arrived: a generation the user was told had failed may gain a charge. One of ours whose state cannot be read is logged and left unchecked.
3. Go on to the next page while some are still unmatched, a `next_cursor` exists, fewer than five pages have been read, and the run's budget of requests allows.
4. If the list was read to its end or to five pages, set `provider_synced_at` on the unmatched ones: the provider has been asked and does not know them.

Five pages are 500 generations, the provider's daily limit, so a failure from the last 24 hours
cannot lie beyond them. In practice the first page is enough: the check runs within a minute of the
failure.

A `429` stops the whole run, as it does for polling. Any other failure of the list request is
logged and leaves the generations unverified for the next run. Requests for the list are taken from
`MAX_REQUESTS_PER_RUN` first, because a missing provider cost matters more than a late status.

The three codes are the ones that can follow a request the provider may have received:
`provider_unavailable` (no answer or a server error), `submission_rejected` (set when the answer
could not be understood, as well as on an explicit refusal) and `wait_timeout` (a wait that may have
included a timed-out request). `content_policy_violation` and `daily_limit_exceeded` are explicit
refusals and are left alone.

Alternative considered: sending the generation again with the same idempotency key, which returns
the provider's generation if it has one. Rejected — if it has none, the send creates one, and the
user has already been told it failed.

### The wait after a 429

`SendGeneration::retryAfterSeconds()` becomes: the larger of the provider's `Retry-After` and the
seconds since submission, kept between 5 and 60. Waiting as
long again as it has already waited doubles the gap — 5, 5, 10, 20, 40, then 60 — with no counter:
the time waited is read from `created_at`. `SendRetry` and the job are unchanged.

### Three attempts for the send job

`#[Tries(3)]` on `SendGenerationJob`. The extra attempts are only used when an attempt does not end:
a worker stopped mid-send makes the queue offer the job again after `retry_after` (90 seconds).
Ordinary retries still go through the delayed copy, each a new job with its own attempts.

An attempt that throws is not changed in effect: `SendGeneration` has already failed the generation
before rethrowing, so the second attempt finds it no longer waiting and does nothing.

### What is reported

`PollingResult` gains `recoveredCount`, and the command's line reads
"Failed N abandoned, recovered N lost, polled N of N due."

## Risks / Trade-offs

- [A list request every minute while unverified failures exist and the provider keeps failing] → at most 24 hours per failure, one to five requests a run, inside the run's budget.
- [A generation accepted by the provider more than 500 generations ago is not found] → that takes a full day of the provider's limit between the failure and the check; the operator report's reconciliation would then show the difference.
- [Longer waits make a waiting generation start later once a slot frees] → at most 60 seconds later than before, in exchange for not starving every other request.
- [Three attempts could send a generation three times] → the idempotency key makes every send after the first return the same generation.
