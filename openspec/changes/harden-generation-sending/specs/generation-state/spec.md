## ADDED Requirements

### Requirement: A local failure that may have reached the provider is checked against it

A generation that failed locally with `provider_unavailable`, `wait_timeout` or
`submission_rejected` may have been accepted by the provider all the same, when the provider's
answer was lost or could not be read. For each such generation that failed in the last 24 hours, but not in
the last two minutes, and has not been checked yet, the polling run SHALL ask the provider for its
generations, and SHALL
apply the provider's state to every one of them that carries the generation's local identifier. A
generation the provider does not list SHALL be recorded as checked and SHALL NOT be asked about
again. If the provider cannot be asked, the generation SHALL stay unchecked for the next run. The two minutes are
there because the check is made once: a request the system gave up on may still be on its way to
the provider, and asking at once could miss it. Replacing a local failure with the provider's state
SHALL be written to the log, whichever way that state arrived.

Generations that failed locally with `content_policy_violation` or `daily_limit_exceeded` SHALL NOT
be checked: the provider refused them outright.

The requests made for this check SHALL count towards the polling run's limit of requests, and the
check SHALL come before the polling of accepted generations.

#### Scenario: The create response was lost

- **WHEN** a generation failed locally with `provider_unavailable` and the provider lists a succeeded generation carrying its local identifier
- **THEN** after the polling run the generation is succeeded with the provider's identifier, usage, provider cost and user charge, and has no error

#### Scenario: The provider never received it

- **WHEN** a generation failed locally with `provider_unavailable` and the provider lists no generation carrying its local identifier
- **THEN** it stays failed, and the next polling run makes no request on its account

#### Scenario: A failure that has only just happened

- **WHEN** a generation failed locally a minute ago
- **THEN** the polling run does not ask the provider about it yet, and asks once two minutes have passed since the failure

#### Scenario: Refused by the provider

- **WHEN** the only local failures are `content_policy_violation` and `daily_limit_exceeded`
- **THEN** the polling run does not ask the provider for its generations

#### Scenario: Nothing to check

- **WHEN** no generation has failed locally in the last 24 hours
- **THEN** the polling run does not ask the provider for its generations

#### Scenario: The provider cannot be asked

- **WHEN** the provider answers the request for its generations with an error
- **THEN** the failed generation stays unchecked and is checked again by the next polling run

#### Scenario: More generations than one page

- **WHEN** the generation carrying the local identifier is on the second page of the provider's list
- **THEN** the polling run asks for the second page and applies its state

#### Scenario: The check uses the run's request limit

- **WHEN** a polling run has local failures to check and accepted generations to poll
- **THEN** it makes at most 20 requests in all, and asks for the provider's list first
