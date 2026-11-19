## Purpose

Lets a signed-in user describe the image they want, see what it will cost before paying for it, and
submit it — or learn clearly why it cannot be submitted right now.

## ADDED Requirements

### Requirement: Only signed-in users reach the generation page

The system SHALL serve the generation page, the estimate and the submission only to signed-in
users. The site root SHALL lead to the generation page, and signing in or registering SHALL land on
it.

#### Scenario: A guest opens the generation page

- **WHEN** a guest requests the generation page
- **THEN** they are redirected to sign in

#### Scenario: The site root

- **WHEN** anyone requests the site root
- **THEN** they are redirected to the generation page

#### Scenario: After signing in

- **WHEN** a user signs in or registers
- **THEN** they are redirected to the generation page

### Requirement: A generation request is validated

The system SHALL accept a generation request made of a prompt of 1 to 4,000 characters, one of the
three sizes (`1024x1024`, `1536x1024`, `1024x1536`), one of the three qualities (`low`, `medium`,
`high`) and at most 4 reference image URLs, each an `http` or `https` URL of at most 2,048
characters. Prompt length SHALL be counted in Unicode characters. An invalid request SHALL be
refused with an error attached to the field that caused it, and nothing SHALL be recorded.

#### Scenario: A valid request is submitted

- **WHEN** a user submits a valid prompt, size and quality with two reference image URLs
- **THEN** a waiting generation is recorded for that user with exactly those values, and the user is returned to the first page of their history

#### Scenario: Missing prompt

- **WHEN** a user submits without a prompt
- **THEN** the submission is refused with an error on the prompt and no generation is recorded

#### Scenario: Prompt too long

- **WHEN** a user submits a prompt of 4,001 characters
- **THEN** the submission is refused with an error on the prompt

#### Scenario: A prompt of 4,000 emoji

- **WHEN** a user submits a prompt of 4,000 emoji
- **THEN** it is accepted

#### Scenario: Unknown size or quality

- **WHEN** a user submits a size or quality outside the allowed values
- **THEN** the submission is refused with an error on that field

#### Scenario: A reference image that is not a web URL

- **WHEN** the second reference image is `ftp://example.com/a.png` or not a URL at all
- **THEN** the submission is refused with an error on the second reference image

#### Scenario: Five reference images

- **WHEN** a user submits five reference image URLs
- **THEN** the submission is refused with an error on the reference images

### Requirement: The estimate is shown before submission

The system SHALL show the estimate for the form's current values as a range of user charges, each
bound displayed by the pricing display rule with its exact amount alongside. The estimate SHALL be
present when the page opens and SHALL follow every change to the prompt, size, quality and number of
reference images. An estimate SHALL be available for an empty prompt. The submit control SHALL show
the upper bound, and the page SHALL state that the exact charge is known after generation and will
not exceed the upper bound.

The estimate shown SHALL be calculated by the same rules as the estimate recorded on submission.

#### Scenario: Estimate on opening the page

- **WHEN** a user opens the generation page
- **THEN** the page carries the estimate for an empty prompt with the default size and quality and no reference images

#### Scenario: Documented example

- **WHEN** an estimate is requested for a 400-character prompt at `high` quality, `1536x1024`, with 2 reference images
- **THEN** the answer is `$0.3578` to `$0.4674`, with exact amounts `0.357781250` and `0.467343750`

#### Scenario: Empty prompt

- **WHEN** an estimate is requested with an empty prompt
- **THEN** an estimate is returned, with input text tokens contributing nothing

#### Scenario: Invalid values

- **WHEN** an estimate is requested with a 4,001-character prompt or an unknown size
- **THEN** no estimate is returned and the error names the field

#### Scenario: The estimate matches what is recorded

- **WHEN** a user submits the values an estimate was just shown for, and pricing has not changed
- **THEN** the recorded estimate equals the one shown

### Requirement: A refused submission is explained

The system SHALL tell the user why a valid request cannot be submitted, and SHALL NOT record a
generation in that case.

When the user already has the maximum number of unfinished generations, the page SHALL show that
submission is unavailable until one finishes, before the user tries, and a submission made anyway
SHALL be refused with the same explanation.

When pricing is unavailable, the page SHALL show that generations cannot be submitted right now in
place of the estimate, and a submission SHALL be refused with that explanation.

#### Scenario: The page knows the limit is reached

- **WHEN** a user with three unfinished generations opens the generation page
- **THEN** the page carries that three of three are in progress, so submission is shown as unavailable

#### Scenario: Submitting over the limit

- **WHEN** a user with three unfinished generations submits a valid request
- **THEN** it is refused with an explanation that three generations are in progress, and no generation is recorded

#### Scenario: Pricing unavailable on the page

- **WHEN** a user opens the generation page while pricing is unavailable
- **THEN** the page carries no estimate and is marked as unable to submit

#### Scenario: Pricing unavailable for an estimate

- **WHEN** an estimate is requested while pricing is unavailable
- **THEN** the answer says pricing is unavailable instead of giving an estimate

#### Scenario: Submitting while pricing is unavailable

- **WHEN** a user submits a valid request while pricing is unavailable
- **THEN** it is refused with an explanation that pricing is unavailable, and no generation is recorded

### Requirement: Estimate requests are rate limited

The system SHALL limit how many estimates one user can request per minute, and SHALL refuse
requests over the limit without calculating anything.

#### Scenario: Over the limit

- **WHEN** a user requests more estimates in a minute than the limit allows
- **THEN** further requests in that minute are refused as too many requests
