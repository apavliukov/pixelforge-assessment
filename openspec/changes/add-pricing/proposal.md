## Why

Users pay Pixelforge's cost plus a markup, and Pixelforge never returns a price: it reports token
usage and publishes rates. The app needs one place that turns tokens and rates into exact money,
before any generation can be submitted or recorded. Money correctness is the first thing the
assessment is judged on, so it is built and tested first, with no UI and no database tables.

## What Changes

- Represent every amount as a whole number of nanodollars; parse and format decimal strings without floats.
- Calculate what Pixelforge billed for a finished generation from its token usage and the rates fixed on it.
- Calculate what the user is charged: provider cost plus a configurable markup, rounded up to a nanodollar.
- Estimate the cost of a generation before it is submitted, as a minimum–maximum range with the markup included.
- Keep a local copy of Pixelforge's published pricing, refreshed on a schedule, so estimating never waits on a live call.
- Add the Pixelforge API client with its first endpoint, `GET /v1/pricing`.

## Capabilities

### New Capabilities

- `pricing`: exact money amounts, provider cost, user charge with markup, the pre-submission estimate range, and the local copy of the provider's published pricing.

### Modified Capabilities

None.

## Impact

- New code only: a money value object, a price calculator, pricing data objects, the Pixelforge client, a scheduled refresh job.
- New configuration: Pixelforge base URL and API key (`config/services.php`), markup in basis points (`config/pricing.php`).
- `routes/console.php` gains one scheduled job.
- No migrations, no routes, no frontend changes, no new dependencies.
