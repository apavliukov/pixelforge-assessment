# generation-history Specification

## Purpose

Shows a user their own generations with a status they can understand, what each was estimated to
cost and what it actually cost, and the resulting image — and keeps that picture current without
the user reloading the page.

## Requirements

### Requirement: A user sees only their own generations, newest first

The system SHALL show a user their own generations ordered from the most recently submitted, 15 per
page. Another user's generations SHALL never be shown.

#### Scenario: Newest first

- **WHEN** a user with three generations opens their history
- **THEN** the three are listed from the most recently submitted to the oldest

#### Scenario: Another user's generations

- **WHEN** two users each have generations and one opens their history
- **THEN** only that user's generations are listed

#### Scenario: More than one page

- **WHEN** a user has 16 generations
- **THEN** the first page lists the newest 15 and the second page lists the remaining one

### Requirement: Each generation shows its request, status and time

The system SHALL show for each generation its prompt, size, quality, the number of reference images
when there are any, the time it was submitted, and its status in plain words: `Waiting to start` for waiting, `Queued`,
`Generating` for processing, `Done` for succeeded and `Failed`. The submission time SHALL be given
as an absolute moment that the browser displays in the user's own time zone.

#### Scenario: Status wording

- **WHEN** a history lists one generation in each status
- **THEN** they read `Waiting to start`, `Queued`, `Generating`, `Done` and `Failed`

### Requirement: Estimate and actual charge are shown side by side

The system SHALL show every generation's estimate as the range recorded at submission. A succeeded
generation SHALL also show the user charge. A failed generation SHALL show a charge of `$0.00`. An
unfinished generation SHALL show no charge. Every amount SHALL follow the pricing display rule and
carry its exact value alongside.

Provider cost and the markup SHALL NOT be exposed to the user.

#### Scenario: Unfinished generation

- **WHEN** a queued generation is listed
- **THEN** it shows its estimate range and no charge

#### Scenario: Succeeded generation

- **WHEN** a generation that succeeded with a user charge of 467,343,750 nanodollars is listed
- **THEN** it shows a charge of `$0.4674` with exact value `0.467343750`, next to its estimate range

#### Scenario: Failed generation

- **WHEN** a failed generation is listed
- **THEN** it shows a charge of `$0.00`

#### Scenario: Operator figures stay private

- **WHEN** a succeeded generation is listed
- **THEN** the data sent to the user's browser contains neither the provider cost nor the markup

### Requirement: A failure is explained in the user's terms

The system SHALL show a failed generation with a reason chosen by its error code, and every reason
SHALL state that the user was not charged:

| Error code                 | What the user is told                                                    |
| -------------------------- | ------------------------------------------------------------------------ |
| `content_policy_violation` | the prompt was rejected by the content policy; change it and try again   |
| `provider_unavailable`     | the image service was overloaded; try again in a few minutes             |
| `daily_limit_exceeded`     | today's generation limit is used up; try again tomorrow                  |
| `wait_timeout`             | the image service was busy for too long; try again                       |
| `generation_failed`        | the image could not be generated for this prompt; try again or change it |
| any other code             | something went wrong on our side                                         |

The provider's own error message SHALL NOT be shown to the user.

#### Scenario: Each known code has its own reason

- **WHEN** failed generations with each of the five known codes are listed
- **THEN** each shows a different reason, and every reason ends by saying the user was not charged

#### Scenario: Unknown code

- **WHEN** a failed generation with code `submission_rejected` or a code never seen before is listed
- **THEN** it shows the general reason and says the user was not charged

#### Scenario: Provider message stays private

- **WHEN** a failed generation with a provider error message is listed
- **THEN** the data sent to the user's browser does not contain that message

### Requirement: The image is shown while its link works

The system SHALL show a succeeded generation's image while its link has not expired, and SHALL mark
the image as expired afterwards without giving out the dead link. The page SHALL show a placeholder
for an expired image, and SHALL fall back to the same placeholder if an image fails to load.

#### Scenario: Fresh image

- **WHEN** a succeeded generation whose image link expires in the future is listed
- **THEN** it carries the image link and is not marked expired

#### Scenario: Expired image

- **WHEN** a succeeded generation whose image link expired a minute ago is listed
- **THEN** it carries no image link and is marked expired

#### Scenario: Unfinished or failed generation

- **WHEN** a generation that has not succeeded is listed
- **THEN** it carries no image link and is not marked expired

### Requirement: History stays current while generations are unfinished

The page SHALL refresh the history and the count of unfinished generations every few seconds while
the user has any unfinished generation, on whichever page of the history it is listed, without
reloading the rest of the page or disturbing the form, and SHALL stop refreshing once all of them
are final.

#### Scenario: A refresh carries only the history

- **WHEN** the page asks for a refresh of the history
- **THEN** the answer contains the history and the unfinished count and does not recalculate the estimate

#### Scenario: A generation finishes

- **WHEN** a generation shown as `Generating` succeeds at the provider and the state is applied
- **THEN** the next refresh shows it as `Done` with its charge and image
