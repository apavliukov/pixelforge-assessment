## Purpose

Takes a user's request for an image, records it with the price range the user agreed to, and gets it
accepted by the provider exactly once — or fails it with a reason and no charge.

## ADDED Requirements

### Requirement: A submitted generation is recorded with the price the user agreed to

When a user submits a generation, the system SHALL record it as a waiting generation together with
the request (prompt, size, quality, reference images), the estimate minimum and maximum calculated
from the current pricing, and the markup in force at that moment. The recorded estimate and markup
SHALL NOT change afterwards.

#### Scenario: Submission stores the estimate and markup

- **WHEN** a user submits a 400-character prompt at `1536x1024`, `high` quality, with 2 reference images, at the documented pricing and a price multiplier of 12,500 basis points
- **THEN** a waiting generation exists for that user with estimate minimum 357,781,250 and maximum 467,343,750 nanodollars and price multiplier 12,500

#### Scenario: A later markup change does not touch existing generations

- **WHEN** the configured markup changes after a generation was submitted
- **THEN** that generation still carries the markup recorded at submission, and its user charge is calculated with it

#### Scenario: Pricing is unavailable

- **WHEN** a user submits a generation and no recent pricing can be obtained
- **THEN** the submission is refused and no generation is recorded

### Requirement: A user has at most three unfinished generations

The system SHALL refuse a submission from a user who already has three generations that are
waiting, queued or processing. Finished generations SHALL NOT count.

#### Scenario: Fourth unfinished generation is refused

- **WHEN** a user with three unfinished generations submits another
- **THEN** the submission is refused and no generation is recorded

#### Scenario: Finished generations free the limit

- **WHEN** a user with two unfinished and five finished generations submits another
- **THEN** the generation is recorded

#### Scenario: Another user's generations do not count

- **WHEN** a user with no generations submits one while another user has three unfinished
- **THEN** the generation is recorded

### Requirement: A generation is sent to the provider exactly once

The system SHALL send each waiting generation to the provider in the background, using the
generation's local identifier as the idempotency key and as `metadata.local_id`. Every repeated send
of the same generation SHALL use the same key and the same parameters.

#### Scenario: The provider accepts the generation

- **WHEN** the provider answers the create request with `202` and a generation object in status `queued`
- **THEN** the generation is accepted: it carries the provider's identifier and rates and its status is `queued`

#### Scenario: A repeated send reuses the idempotency key

- **WHEN** a generation is sent a second time after a failed attempt
- **THEN** the second request carries the same `Idempotency-Key` and the same body as the first

#### Scenario: A generation that is no longer waiting is not sent

- **WHEN** a send is due for a generation that has already been accepted or has failed
- **THEN** no request is made to the provider

### Requirement: A generation waits while the provider has no room

When the provider answers `429` with `concurrency_limit_exceeded` or `rate_limit_exceeded`, the
system SHALL keep the generation waiting and send it again after the delay given in `Retry-After`.
A generation that has waited 10 minutes since submission SHALL fail with the code `wait_timeout`.

#### Scenario: All provider slots are taken

- **WHEN** the provider answers `429 concurrency_limit_exceeded` with `Retry-After: 7`
- **THEN** the generation stays waiting and is sent again no sooner than 7 seconds later

#### Scenario: The wait limit is reached

- **WHEN** a send is due for a generation submitted more than 10 minutes ago that is still waiting
- **THEN** the generation fails with the code `wait_timeout` and no request is made

### Requirement: Temporary provider errors are retried a limited number of times

When the create request fails with `503`, any other `5xx`, or a network error, the system SHALL send
the generation again up to five times, waiting 2, 4, 8, 16 and 32 seconds. If the fifth retry also
fails, the generation SHALL fail with the code `provider_unavailable`.

#### Scenario: The provider is overloaded once

- **WHEN** the provider answers `503 overloaded` to the first send
- **THEN** the generation stays waiting and is sent again 2 seconds later

#### Scenario: The provider stays unavailable

- **WHEN** the first send and all five retries fail with `503` or a network error
- **THEN** the generation fails with the code `provider_unavailable`

### Requirement: A rejected generation fails at once with a reason

The system SHALL fail the generation without further sends when the provider rejects the create
request, and SHALL record a code that tells the user what happened:

- `400 content_policy_violation` → the code `content_policy_violation`;
- `429 daily_limit_exceeded` → the code `daily_limit_exceeded`;
- any other `4xx` (such as `401`, `409`, `422`) → the code `submission_rejected`, and the error is reported as a defect of this application.

The provider's own message SHALL be recorded alongside the code.

#### Scenario: The prompt violates the content policy

- **WHEN** the provider answers `400 content_policy_violation`
- **THEN** the generation fails with the code `content_policy_violation` and is not sent again

#### Scenario: The daily limit is exhausted

- **WHEN** the provider answers `429 daily_limit_exceeded`
- **THEN** the generation fails with the code `daily_limit_exceeded` and is not sent again

#### Scenario: The provider rejects the request as invalid

- **WHEN** the provider answers `422 validation_failed`
- **THEN** the generation fails with the code `submission_rejected` and the error is reported

### Requirement: A generation that was never accepted costs nothing

A generation that fails before the provider accepted it SHALL have a provider cost of zero and a
user charge of zero.

#### Scenario: Failed submission

- **WHEN** a generation fails with `content_policy_violation`
- **THEN** its provider cost and its user charge are both 0 nanodollars
