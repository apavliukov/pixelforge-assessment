## 1. Waiting for a slot

- [x] 1.1 Write tests for the new scenarios of "A generation waits while the provider has no room" (Retry-After honoured, the wait grows with the time waited, a just-submitted generation, a long wait) and verify they fail
- [x] 1.2 Change the delay in `SendGeneration` and verify those tests and the existing ones pass

## 2. Interrupted send

- [x] 2.1 Give `SendGenerationJob` three attempts; test that the job declares them and that a second run for a generation the first run already failed sends nothing; verify they pass

## 3. Lost create response

- [x] 3.1 Add `PixelforgeClient::generations()` returning a page of provider states and the next cursor, with tests against a faked response (cursor passed, items parsed, last page) and verify they pass
- [x] 3.2 Add the `unverifiedLocalFailures` scope with tests of what it includes and leaves out (the three codes, the two refused codes, older than 24 hours, already checked, has a provider identifier) and verify they pass
- [x] 3.3 Write tests for every scenario of "A local failure that may have reached the provider is checked against it" and verify they fail
- [x] 3.4 Add the step to `PollDueGenerations`, `recoveredCount` to `PollingResult` and the command's summary line; verify the tests from 3.3 and the existing polling tests pass
- [x] 3.5 Check a local failure only once it is two minutes old, and log when a local failure is replaced by the provider's state; test the delay

## 4. Wrap-up

- [x] 4.1 Update `docs/DECISIONS.md`: the wait after a 429, and that a local failure is now checked against the provider's list
- [x] 4.2 Run Pint and Rector on the changed PHP files, `vendor/bin/sail composer types:check` and the full test suite; verify all pass
- [x] 4.3 With the local Pixelforge key, run `generations:poll` and verify it makes no list request when there is nothing to check; then check the one local `provider_unavailable` failure in the local database is marked as checked after a run
