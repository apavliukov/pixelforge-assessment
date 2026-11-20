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

**The markup is stored as a price multiplier in basis points (`12500` = ×1.25) and copied onto every
generation.**
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
Estimating must not depend on a live HTTP call. If the cache is empty, or no refresh has succeeded for
an hour, the pricing is fetched once synchronously; if that fails too, submitting is blocked, because we never submit without showing a
price. A malformed pricing response is rejected and the last good one stays.
Rejected: a `pricing` table. Pricing is derived data whose source of truth is the provider, and
history does not need it: every generation stores the rates it was billed at.

## Generation lifecycle

**The actual cost is calculated from the generation's own `usage` and `rates`, exactly once, on the
transition to `succeeded`.**
Rates are fixed when a generation is created, so the current pricing must not be used.

**One action applies provider state, used by the create response, webhooks and polling.**
It runs in a transaction with a row lock, only moves the status forward, and never leaves a final
status reported by the provider. Applying the same state twice changes nothing, which is what makes
"never recorded twice" hold even without event de-duplication.

**Only the provider's word is final. A failure we decide ourselves is provisional.**
If the create response is lost, we may mark a generation failed that Pixelforge accepted, ran and
billed. Leaving it failed would hide a real provider cost and the report would never reconcile. So a
local failure is replaced by any state the provider later reports. Cost: in that rare case a user
sees "failed" turn into "succeeded" with a charge. A webhook is not the only way that state
arrives: polling cannot ask about a generation that has no provider identifier, so after such a
failure it reads the provider's list of generations once and looks for ours by `metadata.local_id`. It
waits two minutes first, because it asks only once and the request it gave up on may still arrive.

**An accepted generation is never failed by our own timeout.**
For the same reason: a local "failed" on an accepted generation is a provider cost we might never
record. It is polled until the provider gives a final status — every minute for the first 10
minutes, every 10 minutes after.

**A user charge above the shown maximum is recorded as is.**
It can happen if rates change between the estimate and the provider accepting the generation.
Capping it would break "provider cost × markup"; the operator report shows such generations instead.

**A user may have at most 3 unfinished generations.**
The provider's 3 slots and 500 generations a day are shared by the whole app; without a per-user
limit one user blocks everyone.

**When the provider's three concurrency slots are taken, the generation waits in our queue.**
The limit is shared by all users of the app, so it is a normal situation, not an error. The job
sends again after waiting as long as the generation has already waited, between 5 and 60 seconds
and never less than `Retry-After`: retrying every 5 seconds would cost 12 requests a minute per
waiting generation, and five of them would use up the provider's 60 a minute for the whole app.
After 10 minutes of waiting the generation fails with a clear message.

**`503 overloaded` is retried up to 5 times with 2, 4, 8, 16, 32 second delays, then fails.**
The docs ask for capped retries with growing delays; the numbers are our choice.

**A send that must be tried again is queued as a new delayed job, not released.**
Two things are counted while a generation waits to be sent: how long it has waited for a free slot,
and how many times the provider failed. Releasing a job puts the same payload back, with one
attempts counter and no room for the second number. A new job carries the failure count in its
payload, and the wait is read from the generation itself.

**A generation that fails after being accepted is not retried automatically.**
A retry is a new generation with a new price, and the user has not agreed to it. The failure
message says they were not charged, and submitting again is their choice.

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

**The operator report is guarded by a gate backed by an `is_operator` column that only the seeder sets.**
Authorization goes through a gate, where it belongs; only the source of truth is simple. The column
is not fillable, so neither registration nor a profile change can set it. It replaced a list of
operator ids in the environment once operators came from a seeder: the list had to agree with the
order rows were inserted in. A boolean is a shortcut for this assessment; with a second kind of
privileged user it should become roles and permissions (`spatie/laravel-permission`), which changes
the gate's body and nothing else. Rejected for now: a roles package, for one permission.

**Demo accounts and one-click sign-in, behind a flag.**
The assignment's submission form has room for a repository, a URL and an email — nowhere to give
reviewers a login or say where the report is. So the sign-in page offers buttons for two demo users
and a demo operator, created by the seeder with passwords nobody knows. A user is a demo account
because the seeder marked it (`demo_account`), not because of its email, so nobody's own account
can be opened by a demo button and a demo account's profile cannot be changed. The buttons and their route
exist only while `DEMO_LOGIN_ENABLED` is on; a real product leaves it off. Cost: on the demo, anyone
can spend the provider key's quota and read the operator report. The per-user limit and the
provider's daily limit bound the first; the report holds only demo data and whoever chose to
register. Registration stays, so a reviewer can also check with an account of their own.

**Users cannot delete their accounts, and the database refuses to delete a user who has generations.**
The starter kit's "Delete account" cascaded to the user's generations, which would erase provider
costs the operator has already paid and leave the report unable to reconcile for good. Rejected:
soft-deleting users, which is a feature of its own and not asked for.

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

**Web server, two queue workers and scheduler share one container.**
Render's free tier has no background workers. The start script restarts each worker in a loop. On a
paid plan these become separate services with no code change.

**Sends and webhook results run on separate queues and workers.**
A worker runs one job at a time, and a send to Pixelforge can take up to 10 seconds when it is
slow; results that have already arrived must not wait behind a burst of sends. Polling is a
scheduled command run by the scheduler's own process, not a queued job, for the same reason.
Cost: a second PHP process in a 512 MB container. If it does not fit, one worker taking
`webhooks,submissions` in that order is a one-line fallback.
