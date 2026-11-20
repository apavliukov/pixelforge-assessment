## Why

Three paths in sending a generation still end badly. One can lose a provider cost for good, which
breaks the one thing the operator report must get right; one can use up the provider's request
limit for the whole app; one leaves a user looking at "Waiting to start" for a quarter of an hour
after a restart.

## What Changes

- **A lost create response is recovered without a webhook.** When a send fails on our side — the provider could not be reached, answered with a server error, or answered with something unreadable — the provider may still have accepted the generation. Until now only a webhook could correct that, because polling asks about generations by their provider identifier and such a generation has none. Now the polling run asks the provider for its list of generations, finds the ones that carry our identifiers, and applies their state. Each such failure is checked once.
- **Waiting for a free slot backs off.** A generation refused with `429` was sent again every 5 seconds for up to 10 minutes: 12 requests a minute each, so five waiting generations used the provider's whole limit of 60 and starved polling. Now each wait is as long as the generation has already waited, between 5 and 60 seconds, and never shorter than the provider's `Retry-After`.
- **An interrupted send is run again.** The send job had one attempt, so a worker killed in the middle of it — a container restart — left the generation waiting until the 15-minute sweep failed it. The job now has three attempts. A repeated send is safe: it carries the same idempotency key.

Out of scope: matching every provider generation against ours (the operator report's
reconciliation already shows a difference), and changing the limits themselves.

## Capabilities

### New Capabilities

None.

### Modified Capabilities

- `generation-submission`: how long a generation waits between sends while the provider has no room, and what happens to a send that was interrupted.
- `generation-state`: local failures that may have reached the provider are checked against the provider's list of generations.

## Impact

- `SendGeneration`: the delay after a `429`.
- `SendGenerationJob`: three attempts instead of one.
- `PollDueGenerations`: a step that checks unverified local failures against the provider's list, inside the run's existing budget of 20 requests; `PollingResult` and the command's summary line gain the number recovered.
- `PixelforgeClient`: a method for the provider's list of generations, one page at a time.
- `Generation`: a scope for local failures that have not been checked against the provider.
- `docs/DECISIONS.md`: the entries on waiting for a slot and on local failures.
- No schema changes, no new dependencies.
