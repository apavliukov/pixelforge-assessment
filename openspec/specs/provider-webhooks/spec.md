# provider-webhooks Specification

## Purpose

Receives the provider's webhook events, accepts only those the provider really sent, and makes sure
each one is applied to its generation once, however many times it is delivered.

## Requirements

### Requirement: Only events signed by the provider are accepted

The system SHALL accept a webhook request only when its `Pixelforge-Signature` header (`t=<unix
time>,v1=<hex>`) carries a `v1` equal to the HMAC-SHA256 of `<t>.<raw request body>` under the
configured webhook secret, and `t` is no more than 5 minutes from the current time. Any other
request SHALL be answered with `400` and SHALL leave no trace. The endpoint SHALL NOT require a
session, a login or a CSRF token.

#### Scenario: Valid signature

- **WHEN** a request arrives with a signature computed from its raw body and the configured secret, timestamped now
- **THEN** the response is `200`

#### Scenario: Wrong signature

- **WHEN** a request arrives whose `v1` does not match its body
- **THEN** the response is `400` and no event is stored

#### Scenario: Stale timestamp

- **WHEN** a correctly signed request arrives with `t` 6 minutes in the past
- **THEN** the response is `400` and no event is stored

#### Scenario: Missing or malformed header

- **WHEN** a request arrives without a `Pixelforge-Signature` header
- **THEN** the response is `400`

#### Scenario: No secret is configured

- **WHEN** the webhook secret is empty and a request arrives signed with an empty secret
- **THEN** the response is `400`

### Requirement: An accepted event is stored before it is acknowledged

For every accepted event other than `ping` the system SHALL store the event identifier, its type and
the raw body, and SHALL schedule it to be applied, before answering `200`. Storing and scheduling
SHALL succeed or fail together. A `ping` event SHALL be answered with `200` and nothing else.

#### Scenario: A generation event is accepted

- **WHEN** a signed `generation.succeeded` event arrives
- **THEN** the response is `200`, the event is stored with its raw body, and it is scheduled to be applied

#### Scenario: Ping

- **WHEN** a signed `ping` event arrives
- **THEN** the response is `200` and no event is stored

### Requirement: An event is processed once, however often it is delivered

The system SHALL treat a delivery whose event identifier is already stored as a duplicate: it SHALL
answer `200` and SHALL NOT store or schedule anything.

#### Scenario: The same event is delivered twice

- **WHEN** a signed `generation.succeeded` event is delivered twice
- **THEN** both responses are `200`, one event is stored, and the generation's cost is recorded once

### Requirement: A stored event is applied to its generation

The system SHALL apply the generation object carried by a stored `generation.*` event as a provider
state and SHALL mark the event as processed. An event for which no generation is found SHALL be
marked as processed and logged.

#### Scenario: A success event completes the generation

- **WHEN** a stored `generation.succeeded` event for an accepted generation is applied
- **THEN** the generation is succeeded with its cost recorded, and the event is marked as processed

#### Scenario: The event arrives before the create response

- **WHEN** a stored event carries `metadata.local_id` of a waiting generation that has no provider identifier yet
- **THEN** the generation is found by that identifier and updated

#### Scenario: Unknown generation

- **WHEN** a stored event matches no generation
- **THEN** the event is marked as processed and a warning is logged
