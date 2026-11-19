# generation-state Specification

## Purpose

Keeps each generation in step with what the provider reports about it, whichever channel the report
arrives through and in whatever order, and records the money for it exactly once.

## Requirements

### Requirement: Provider state is applied the same way from every channel

The system SHALL apply a provider state to the matching generation identically whether it arrived
in the create response, in a webhook event or from polling. The generation SHALL be found by the
provider's identifier, and, when no generation carries it yet, by `metadata.local_id`. A provider
state SHALL record the provider's identifier and rates on a generation that does not have them yet,
whatever its status.

#### Scenario: State arrives before the create response

- **WHEN** a provider state with status `processing` and `metadata.local_id` of a waiting generation arrives before the create response
- **THEN** that generation carries the provider's identifier and its status is `processing`

#### Scenario: The create response arrives late

- **WHEN** the create response with status `queued` is applied to a generation that is already `processing`
- **THEN** the status stays `processing`

#### Scenario: No generation matches

- **WHEN** a provider state matches no generation by identifier or by `metadata.local_id`
- **THEN** nothing is changed and the mismatch is logged

### Requirement: Status only moves forward

The system SHALL order statuses as waiting, then queued, then processing, then the final statuses
succeeded and failed, and SHALL apply a provider state only when its status is further along than
the generation's current status. A final status reported by the provider SHALL never change.

#### Scenario: A late `processing` after `succeeded`

- **WHEN** a provider state with status `processing` arrives for a generation that has succeeded
- **THEN** the status, usage, provider cost and user charge are unchanged

#### Scenario: Steps are skipped

- **WHEN** a provider state with status `succeeded` arrives for a generation that is still `queued`
- **THEN** the generation is succeeded

#### Scenario: A second, different final status

- **WHEN** a provider state with status `succeeded` arrives for a generation the provider already reported as failed
- **THEN** the generation stays failed with zero cost, and the contradiction is logged

### Requirement: Money is recorded exactly once, on success

On the transition to succeeded the system SHALL record the generation's usage, calculate the
provider cost from that usage and the generation's own rates, calculate the user charge from the
provider cost and the markup recorded at submission, and store the image address and its expiry.
Applying the same or any later provider state SHALL NOT change these values.

#### Scenario: Documented succeeded generation

- **WHEN** a generation with price multiplier 12,500 succeeds with 12 input text, 765 input image, 6,450 output and 3,184 reasoning tokens at rates `5.00`, `10.00`, `40.00` and `12.50`
- **THEN** its provider cost is 305,510,000 and its user charge is 381,887,500 nanodollars

#### Scenario: The same success is applied twice

- **WHEN** the same succeeded provider state is applied a second time
- **THEN** the generation's provider cost and user charge are unchanged

#### Scenario: The generation's rates differ from the published ones

- **WHEN** a generation accepted at the output rate `40.00` succeeds after the published output rate has changed to `50.00`
- **THEN** its provider cost is calculated with `40.00`

#### Scenario: The charge exceeds the shown maximum

- **WHEN** a generation succeeds with a user charge above its recorded estimate maximum
- **THEN** the full user charge is recorded and the estimate is left as it was

### Requirement: A failed generation costs nothing

When the provider reports a generation as failed, the system SHALL record the provider's error code
and message and SHALL set the provider cost and the user charge to zero.

#### Scenario: The provider reports a failure

- **WHEN** a provider state with status `failed` and error code `generation_failed` arrives for a processing generation
- **THEN** the generation is failed with that code and message, and its provider cost and user charge are both 0 nanodollars

### Requirement: A local failure gives way to the provider

A failure the system decided itself, for a generation that carries no provider identifier, SHALL be
provisional: any provider state for that generation SHALL replace it, whatever its status, and clear
the locally recorded error.

#### Scenario: A generation failed locally turns out to have succeeded

- **WHEN** a generation failed with `provider_unavailable` and a succeeded provider state arrives for it by `metadata.local_id`
- **THEN** the generation is succeeded, carries the provider's identifier, usage, provider cost and user charge, and has no error

#### Scenario: A generation failed locally turns out to be running

- **WHEN** a generation failed with `wait_timeout` and a provider state with status `processing` arrives for it
- **THEN** the generation is `processing` and has no error

### Requirement: Unfinished accepted generations are polled

The system SHALL ask the provider for the state of every accepted generation that is not finished
and has had no provider state recently: every minute during the first 10 minutes after submission,
every 10 minutes after that. An accepted generation SHALL never be failed by the system's own
timeout. One polling run SHALL make at most 20 requests and SHALL stop when the provider answers
`429`.

#### Scenario: A missed webhook is recovered

- **WHEN** an accepted generation has had no provider state for over a minute and the provider reports it as succeeded
- **THEN** after the polling run the generation is succeeded with its cost recorded

#### Scenario: A generation with recent news is left alone

- **WHEN** an accepted generation received a provider state 20 seconds ago
- **THEN** the polling run makes no request for it

#### Scenario: An old generation is polled less often

- **WHEN** an accepted generation submitted an hour ago was last checked 5 minutes ago
- **THEN** the polling run makes no request for it, and its status is unchanged

#### Scenario: The provider rate-limits polling

- **WHEN** the provider answers `429` during a polling run
- **THEN** the run makes no further requests

### Requirement: A generation left waiting is failed

The system SHALL fail, with the code `wait_timeout`, any generation that is still waiting 15 minutes
after submission.

#### Scenario: The send was lost

- **WHEN** a generation has been waiting for 16 minutes
- **THEN** after the polling run it is failed with the code `wait_timeout` and zero cost
