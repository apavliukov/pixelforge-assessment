## 1. Data model

- [x] 1.1 Add `GenerationStatus` (with `isFinal`, `isAheadOf`) and `FailureCode` enums; unit-test the ordering (every status against every other, both finals equal) and verify the test passes
- [x] 1.2 Add `MoneyCast` and the `generations` migration, `Generation` model (`HasUuids`, casts, `user` relation, `User::generations`) and factory with states `waiting`, `accepted`, `processing`, `succeeded`, `failedLocally`; verify `vendor/bin/sail artisan migrate` runs and a test creating each state and reading money columns back as `Money` passes
- [x] 1.3 Add the `webhook_events` migration, `WebhookEvent` model and factory; verify a test that a second row with the same `event_id` is rejected by the database passes

## 2. Provider client

- [x] 2.1 Add `TokenUsage::fromArray` and `GenerationData::fromArray`; unit-test a queued object, the documented succeeded object, a failed object, `succeeded` without `usage` (rejected) and an unknown status (rejected); verify the tests pass
- [x] 2.2 Add `PixelforgeClient::createGeneration` and `getGeneration`; feature-test with `Http::fake()` that the create request carries `Idempotency-Key` and `metadata.local_id` equal to the local identifier and the exact body, and that both return `GenerationData`; verify the tests pass

## 3. Applying provider state

- [x] 3.1 Write feature tests for every scenario of "Provider state is applied the same way from every channel" and "Status only moves forward" in `specs/generation-state/spec.md` and verify they fail
- [x] 3.2 Implement `ApplyProviderState` (lookup, lock, forward-only status, provider id and rates always filled) and verify the tests from 3.1 pass
- [x] 3.3 Add tests for "Money is recorded exactly once, on success" and "A failed generation costs nothing", implement the success and failure branches, and verify the tests pass
- [x] 3.4 Add `FailGenerationLocally` with tests (fails a waiting generation with zero cost; does nothing to an accepted one), then tests for "A local failure gives way to the provider", implement the override, and verify the tests pass

## 4. Submission

- [x] 4.1 Write feature tests for "A submitted generation is recorded with the price the user agreed to" and "A user has at most three unfinished generations" (with `Queue::fake()`), implement `SubmitGenerationInput`, `TooManyUnfinishedGenerationsException` and `SubmitGeneration`, and verify the tests pass and the job is pushed once
- [x] 4.2 Write feature tests for "A generation is sent to the provider exactly once" (accepted, same key on a second send, not sent when no longer waiting), implement `SubmitGenerationJob`'s success path, and verify the tests pass
- [x] 4.3 Add tests for "A generation waits while the provider has no room" (re-dispatched with the `Retry-After` delay; `wait_timeout` after 10 minutes) and "Temporary provider errors are retried a limited number of times" (delays 2/4/8/16/32 s, then `provider_unavailable`), implement both, and verify the tests pass
- [x] 4.4 Add tests for "A rejected generation fails at once with a reason" and "A generation that was never accepted costs nothing", implement the rejection branches and failing on an unexpected error, and verify the tests pass

## 5. Webhooks

- [x] 5.1 Add `services.pixelforge.webhook_secret`, register `routes/api.php` in `bootstrap/app.php`, and write feature tests for every scenario of "Only events signed by the provider are accepted"; implement `VerifyPixelforgeSignature` and verify the tests pass
- [x] 5.2 Write feature tests for "An accepted event is stored before it is acknowledged" and "An event is processed once, however often it is delivered", implement `PixelforgeWebhookController`, and verify the tests pass and `vendor/bin/sail artisan route:list --path=api` shows the route without session middleware
- [x] 5.3 Write feature tests for "A stored event is applied to its generation", implement `ApplyWebhookEventJob`, and verify the tests pass, including the end-to-end case "same event delivered twice → cost recorded once" run with the sync queue

## 6. Polling

- [x] 6.1 Write feature tests for "Unfinished accepted generations are polled" and "A generation left waiting is failed", implement `generations:poll`, and verify the tests pass
- [x] 6.2 Schedule the command every minute with `withoutOverlapping()` in `routes/console.php` and verify `vendor/bin/sail artisan schedule:list` shows it

## 7. Queues

- [x] 7.1 Put `SubmitGenerationJob` on the `submissions` queue and `ApplyWebhookEventJob` on `webhooks`; verify the existing job tests assert the queue name and pass
- [x] 7.2 Start two workers in `docker/production/start.sh` (`--queue=submissions` and `--queue=webhooks,default`), make the local dev listener take all three queues, update the README and the "one container" entry in `docs/DECISIONS.md`; verify locally that a job pushed to each queue is processed

## 8. Wrap-up

- [x] 8.1 Run `vendor/bin/sail bin pint --dirty --format agent`, `vendor/bin/sail bin rector process` on the changed files, `vendor/bin/sail composer types:check` and the full test suite; verify all pass
- [x] 8.2 With the local Pixelforge key and the tunnel running, submit generations through `tinker` with `[succeed]`, `[fail]`, `[blocked]`, `[overloaded]` and `[duplicate]` in the prompt; verify each ends in the expected status, costs match `usage × rates × markup`, and the error body shape and `Retry-After` handling assumed in `design.md` hold
