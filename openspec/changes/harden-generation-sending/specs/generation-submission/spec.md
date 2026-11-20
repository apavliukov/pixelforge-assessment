## MODIFIED Requirements

### Requirement: A generation is sent to the provider exactly once

The system SHALL send each waiting generation to the provider in the background, using the
generation's local identifier as the idempotency key and as `metadata.local_id`. Every repeated send
of the same generation SHALL use the same key and the same parameters. A send that is interrupted
before it finishes, as when its worker is stopped, SHALL be run again, up to three times in all.

#### Scenario: The provider accepts the generation

- **WHEN** the provider answers the create request with `202` and a generation object in status `queued`
- **THEN** the generation is accepted: it carries the provider's identifier and rates and its status is `queued`

#### Scenario: A repeated send reuses the idempotency key

- **WHEN** a generation is sent a second time after a failed attempt
- **THEN** the second request carries the same `Idempotency-Key` and the same body as the first

#### Scenario: A generation that is no longer waiting is not sent

- **WHEN** a send is due for a generation that has already been accepted or has failed
- **THEN** no request is made to the provider

#### Scenario: A send is interrupted

- **WHEN** the worker running a send is stopped before the send finishes
- **THEN** the send is run again, and the generation does not stay waiting until the sweep for lost sends

### Requirement: A generation waits while the provider has no room

When the provider answers `429` with `concurrency_limit_exceeded` or `rate_limit_exceeded`, the
system SHALL keep the generation waiting and send it again later. Each wait SHALL be as long as the
generation has already waited since submission, but no shorter than 5 seconds, no longer than 60
seconds, and never shorter than the delay given in `Retry-After`. A generation that has waited 10
minutes since submission SHALL fail with the code `wait_timeout`.

#### Scenario: All provider slots are taken

- **WHEN** the provider answers `429 concurrency_limit_exceeded` with `Retry-After: 7`
- **THEN** the generation stays waiting and is sent again no sooner than 7 seconds later

#### Scenario: The wait grows with the time already waited

- **WHEN** the provider answers `429` with `Retry-After: 5` to a generation submitted 20 seconds ago
- **THEN** the generation is sent again 20 seconds later

#### Scenario: A generation that has just been submitted

- **WHEN** the provider answers `429` without `Retry-After` to a generation submitted a second ago
- **THEN** the generation is sent again 5 seconds later

#### Scenario: A long wait

- **WHEN** the provider answers `429` to a generation submitted 5 minutes ago
- **THEN** the generation is sent again 60 seconds later

#### Scenario: The wait limit is reached

- **WHEN** a send is due for a generation submitted more than 10 minutes ago that is still waiting
- **THEN** the generation fails with the code `wait_timeout` and no request is made
