# Decisions

Short records of the choices the assessment left open: context, decision, why, what was rejected.

## Scope

**Required part plus the operator report. No balances.**
The report is checked against `GET /v1/usage`, so it directly proves the money is right. Balances
would add a second money subsystem (reservations, ledger, refunds) with its own failure modes.

## Money

**Amounts are integer nanodollars (1 USD = 1,000,000,000), stored as `bigint`. No floats anywhere.**
Pixelforge prices are exact to nine decimals; nanodollars represent them without rounding.

**A small `Money` value object owns parsing, arithmetic and formatting.**
Three things need one home: parsing a rate string such as `"12.50"` without floats, the display
format, and the exact nine-decimal string used to reconcile with `cost_usd`. The frontend receives
ready-made strings and does no money arithmetic.

**Markup is stored in basis points (`12500`) and copied onto every generation.**
Changing the markup later must not rewrite history. If a charge is not a whole nanodollar it is
rounded up, in the operator's favour. With today's rates there is never a remainder.

**Sub-cent amounts are stored exactly and rounded only for display: four decimals, rounded up, exact
value in a tooltip.**
Rounding up keeps one promise intact: a displayed actual cost never looks higher than the displayed
maximum estimate because of different rounding rules.

## Estimate

**The estimate is a range, and the submit button shows the upper bound.**
Text tokens vary by ±15% and reasoning tokens by a per-quality range, so a single number would be
wrong most of the time. The upper bound is a guarantee: the docs give exact maxima.

**Only the backend calculates prices.**
Prompt length is counted in Unicode characters; JavaScript's `length` counts UTF-16 units, so an
emoji would be counted twice. One implementation, one answer.

**Pricing is refreshed by a scheduled job into the cache; request code only reads the cache.**
Estimating must not depend on a live HTTP call. If the cache is empty the pricing is fetched once
synchronously; if that fails too, submitting is blocked, because we never submit without showing a
price. A malformed pricing response is rejected and the last good one stays.
Rejected: a `pricing` table. Pricing is derived data whose source of truth is the provider, and
history does not need it: every generation stores the rates it was billed at.

## Generation lifecycle

**The actual cost is calculated from the generation's own `usage` and `rates`, exactly once, on the
transition to `succeeded`.**
Rates are fixed when a generation is created, so the current pricing must not be used.

**One action applies provider state, used by the create response, webhooks and polling.**
It runs in a transaction with a row lock, only moves the status forward, and never leaves a final
status. Applying the same state twice changes nothing, which is what makes "never recorded twice"
hold even without event de-duplication.

**When the provider's three concurrency slots are taken, the generation waits in our queue.**
The limit is shared by all users of the app, so it is a normal situation, not an error. The job
retries after `Retry-After`. After 10 minutes of waiting the generation fails with a clear message.

**`503 overloaded` is retried up to 5 times with 2, 4, 8, 16, 32 second delays, then fails.**
The docs ask for capped retries with growing delays; the numbers are our choice.

**A generation that fails after being accepted is not retried automatically.**
A retry is a new generation with a new price, and the user has not agreed to it. They get a
"Try again" button that reopens the form with the same parameters.

**Polling is a scheduled command, once a minute, for unfinished generations with no recent news.**
Webhook delivery is not guaranteed.

## Webhooks

**The controller verifies the signature, stores the event, and answers; a queued job applies it.**
The event row (unique `event_id`, raw body) and the job are written in one transaction — the queue
lives in the same database — so an accepted event cannot be lost. A duplicate is caught on insert
and answered with `200`. If our own code has a bug, provider retries would not help; a stored event
can be replayed after the fix.
Rejected: applying state synchronously in the controller. Simpler, but a failure leaves no record.

## Images

**Images are not copied to our storage; an expired image shows a placeholder.**
The assessment allows it. Cost: images disappear 24 hours after completion.

## Access

**Open registration, no email verification.**
Reviewers should get in without friction.

**The operator report is guarded by a gate backed by a list of operator emails in the environment.**
Authorization goes through a gate, where it belongs; only the source of truth is simple. Moving to
roles later means changing one gate body. Rejected: an `is_admin` column, and a roles package.

## Infrastructure

**Postgres everywhere, including tests.**
The duplicate and ordering guarantees rely on unique indexes and row locks; testing them on a
different database would prove little. Cost: tests need the database container running.

**Deployed to Render's free tier with Postgres on Neon.**
Free without a card. Render's own free database expires after 30 days; Neon's does not.
Neon's free plan allows about 400 hours of database uptime a month, and our queue worker keeps the
database awake whenever the container is awake. So the container is allowed to sleep when idle (no
uptime pinger): the first visit after a pause takes up to a minute, and the database quota lasts.

**All proxies are trusted (`trustProxies(at: '*')`).**
Render's load balancer terminates HTTPS and forwards plain HTTP, passing the original scheme, host
and client IP in `X-Forwarded-*` headers. Without trusting them the app generates `http://` asset
URLs (blocked as mixed content) and sees every visitor as the balancer's IP, which breaks login
throttling. The balancer's addresses are not fixed, so they cannot be listed. Cost: a client that
could reach the container directly could spoof its IP; on Render the container is reachable only
through the balancer.

**Web server, queue worker and scheduler share one container.**
Render's free tier has no background workers. The start script restarts the worker in a loop. On a
paid plan these become separate services with no code change.
