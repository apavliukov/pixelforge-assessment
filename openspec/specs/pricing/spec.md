# pricing Specification

## Purpose

Turns Pixelforge token usage and published rates into exact money: what the provider billed, what
the user is charged, and what a generation is expected to cost before it is submitted.

## Requirements

### Requirement: Amounts are exact

The system SHALL represent every money amount as a whole number of nanodollars (1 USD =
1,000,000,000 nanodollars) and SHALL NOT use floating-point arithmetic for money. Decimal strings
SHALL be converted to and from amounts exactly.

#### Scenario: A rate string becomes a per-token price

- **WHEN** the rate `"12.50"` US dollars per 1M tokens is read
- **THEN** the price of one token is 12,500 nanodollars

#### Scenario: A rate that is not a whole number of nanodollars per token is rejected

- **WHEN** the rate `"5.0005"` US dollars per 1M tokens is read
- **THEN** the system reports an error instead of rounding

#### Scenario: An amount is written as an exact decimal string

- **WHEN** the amount 373,875,000 nanodollars is written for reconciliation
- **THEN** the result is `"0.373875000"`

#### Scenario: Text that is not a decimal number is rejected

- **WHEN** the string `"12,50"` is read as an amount
- **THEN** the system reports an error

### Requirement: Provider cost comes from the generation's own usage and rates

The system SHALL calculate the provider cost of a finished generation as the sum, over the four
token types (input text, input image, output, reasoning), of the token count multiplied by the
per-token price taken from the rates recorded on that generation. The result SHALL NOT be rounded.

#### Scenario: Documented worked example

- **WHEN** usage is 115 input text, 1,530 input image, 6,450 output and 8,000 reasoning tokens at rates `5.00`, `10.00`, `40.00` and `12.50`
- **THEN** the provider cost is 373,875,000 nanodollars

#### Scenario: Documented succeeded generation

- **WHEN** usage is 12 input text, 765 input image, 6,450 output and 3,184 reasoning tokens at rates `5.00`, `10.00`, `40.00` and `12.50`
- **THEN** the provider cost is 305,510,000 nanodollars

#### Scenario: Rates differ from the currently published ones

- **WHEN** a generation carries the output rate `40.00` and the published output rate has since changed to `50.00`
- **THEN** the provider cost is calculated with `40.00`

### Requirement: User charge is provider cost plus markup

The system SHALL calculate the user charge as the provider cost multiplied by the configured markup,
expressed in basis points (12,500 for a 25% markup). A result that is not a whole number of
nanodollars SHALL be rounded up.

#### Scenario: Markup on the worked example

- **WHEN** the provider cost is 373,875,000 nanodollars and the markup is 12,500 basis points
- **THEN** the user charge is 467,343,750 nanodollars

#### Scenario: A fractional result is rounded up

- **WHEN** the provider cost is 1 nanodollar and the markup is 12,500 basis points
- **THEN** the user charge is 2 nanodollars

#### Scenario: Nothing was billed

- **WHEN** the provider cost is 0
- **THEN** the user charge is 0

### Requirement: Cost is estimated as a range before submission

The system SHALL estimate the user charge for a prompt, size, quality and number of reference images
as a minimum and a maximum, using the published pricing:

- input text tokens: with `e = ceil(characters / characters per text token)`, the minimum is `floor(e × (1 − variance))` and the maximum is `ceil(e × (1 + variance))`;
- input image tokens: the published count per reference image, multiplied by the number of images;
- output tokens: the published count for the chosen quality and size;
- reasoning tokens: the published minimum and maximum for the chosen quality.

Characters SHALL be counted as Unicode code points, so an emoji built from several code points
(a skin tone, a joined family) counts as several characters. Both bounds SHALL include the markup. The actual
charge for a generation with the same inputs and rates SHALL never exceed the maximum.

#### Scenario: Documented maximum

- **WHEN** a 400-character prompt is estimated at `high` quality, `1536x1024`, with 2 reference images
- **THEN** the maximum is 467,343,750 nanodollars and the minimum is 357,781,250 nanodollars

#### Scenario: No reference images

- **WHEN** a prompt is estimated with no reference images
- **THEN** input image tokens contribute nothing to either bound

#### Scenario: Emoji count as one character each

- **WHEN** a prompt of five emoji is estimated
- **THEN** it is counted as 5 characters, giving `e = 2`

#### Scenario: No published output count for the chosen combination

- **WHEN** the published pricing has no output token count for the chosen quality and size
- **THEN** the system reports an error instead of producing an estimate

### Requirement: Amounts are displayed with four decimals, rounded up

The system SHALL display an amount in US dollars with four decimal places, rounding up, and SHALL
display a zero amount as `$0.00`. The exact amount SHALL remain available alongside the displayed one.

#### Scenario: A sub-cent remainder is rounded up

- **WHEN** 467,343,750 nanodollars is displayed
- **THEN** the result is `$0.4674`

#### Scenario: An amount that already fits is unchanged

- **WHEN** 258,000,000 nanodollars is displayed
- **THEN** the result is `$0.2580`

#### Scenario: Zero

- **WHEN** 0 nanodollars is displayed
- **THEN** the result is `$0.00`

### Requirement: Published pricing is kept locally and refreshed on a schedule

The system SHALL keep a local copy of the pricing Pixelforge publishes and SHALL refresh it every
five minutes. Estimates SHALL be calculated from the local copy. A response that is missing a
required value or contains a malformed one SHALL be rejected, leaving the previous copy in place.
When there is no local copy, or the copy is more than an hour old, the system SHALL fetch the
pricing on demand; if that fails, it SHALL report that pricing is unavailable rather than estimate
from an old copy.

#### Scenario: Scheduled refresh replaces the local copy

- **WHEN** the scheduled refresh runs and Pixelforge returns valid pricing
- **THEN** later estimates use the new pricing

#### Scenario: Pixelforge is unreachable during a refresh

- **WHEN** the scheduled refresh runs and the pricing request fails
- **THEN** the previous local copy stays in use

#### Scenario: Malformed pricing is rejected

- **WHEN** the scheduled refresh receives pricing without the `reasoning` ranges
- **THEN** the previous local copy stays in use

#### Scenario: First use with no local copy

- **WHEN** an estimate is requested and there is no local copy
- **THEN** the pricing is fetched from Pixelforge, stored, and used

#### Scenario: No local copy and Pixelforge is unreachable

- **WHEN** an estimate is requested, there is no local copy, and the pricing request fails
- **THEN** the system reports that pricing is unavailable and produces no estimate

#### Scenario: The local copy is over an hour old

- **WHEN** an estimate is requested, the local copy was fetched more than an hour ago, and Pixelforge returns valid pricing
- **THEN** the new pricing is fetched, stored, and used

#### Scenario: The local copy is over an hour old and Pixelforge is unreachable

- **WHEN** an estimate is requested, the local copy was fetched more than an hour ago, and the pricing request fails
- **THEN** the system reports that pricing is unavailable and produces no estimate

### Requirement: The markup is never below one

The system SHALL refuse to estimate, and therefore to record, a generation while the configured
markup is below ×1 (10,000 basis points), and SHALL report this as a defect of the application
rather than as pricing being unavailable. A user charge is therefore never below its provider cost.

#### Scenario: Markup below one

- **WHEN** the markup is configured as 9,999 basis points and an estimate is requested
- **THEN** no estimate is produced and the error names the smallest allowed markup

#### Scenario: No markup

- **WHEN** the markup is configured as exactly 10,000 basis points
- **THEN** estimates are produced and the user charge equals the provider cost
