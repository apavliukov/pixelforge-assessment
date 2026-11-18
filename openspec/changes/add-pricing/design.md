## Context

A fresh Laravel 13 starter kit: no domain code yet. `docs/DECISIONS.md` records the product-level
choices this change implements (nanodollars, `Money`, markup in basis points, backend-only pricing,
pricing in the cache refreshed by a scheduled job). This document covers only how the code is laid out.

The live `GET /v1/pricing` response was checked on 2026-11-18 and matches the documentation; the
duplicated `1536x1024` key in the docs' `medium` example is a typo in the docs, not in the API.

## Goals / Non-Goals

**Goals:**

- One implementation of every price rule, usable later by the estimate endpoint, the state-applying action and the operator report.
- Pure calculation code that is unit-tested without a database or HTTP.

**Non-Goals:**

- No `Generation` model, routes, controllers or frontend.
- No currency support, no general-purpose money library.

## Decisions

**Layout.** New folders under `app/`, each holding one kind of thing:

| Path                                             | Contents                                                              |
| ------------------------------------------------ | --------------------------------------------------------------------- |
| `app/Pricing/Money.php`                          | `final readonly` value object wrapping `int $nano`                    |
| `app/Enums/ImageSize.php`, `ImageQuality.php`    | the three sizes and three qualities                                   |
| `app/Dto/Pixelforge/TokenRates.php`              | per-token price for each of the four token types                      |
| `app/Dto/Pixelforge/TokenUsage.php`              | the four token counts                                                 |
| `app/Dto/Pixelforge/PricingData.php`             | rates plus the token tables from `/v1/pricing`                        |
| `app/Dto/Pricing/PriceEstimate.php`              | `min` and `max` as `Money`                                            |
| `app/Pricing/PriceCalculator.php`                | `providerCost`, `userCharge`, `estimate`                              |
| `app/Pricing/PricingCatalog.php`                 | `current()` reads the cache, `refresh()` fetches and stores           |
| `app/Exceptions/PricingUnavailableException.php` | thrown by `current()` when no recent pricing can be had               |
| `app/Http/Clients/PixelforgeClient.php`          | transport: base URL (a constant), token, `->throw()`, mapping to DTOs |
| `app/Jobs/RefreshPricingJob.php`                 | scheduled trigger that calls `PricingCatalog::refresh()`              |

**`Money` API is only what the three callers need.** `fromDecimalString`, `fromNano`, `plus`,
`times(int)`, `applyBasisPoints(int)` (rounds up), `toDecimalString()` (nine decimals), `format()`
(four decimals, rounded up), `isZero`. No subtraction, division or comparison until something needs
them. Parsing is a regex plus integer arithmetic; `bcmath` is not required.
_Alternative:_ `brick/money` — correct, but a dependency for six methods.

**Per-token price is derived by exact division.** A rate is parsed as dollars per 1M tokens into
nanodollars, then divided by 1,000,000; a remainder is an error. This reuses the one parser instead
of adding a second "scaled by 1000" rule.

**The text-token variance arrives as a JSON float (`0.15`).** It is converted once, via its string
form, into basis points (`1500`), and all estimate arithmetic stays in integers:
`min = intdiv(e × 8500, 10000)`, `max = ceil(e × 11500 / 10000)` done with integer division.

**`PricingData` validates in its constructor path (`fromArray`)** and throws
`UnexpectedValueException` naming the missing or malformed key. The cache stores the validated raw
array with a one-hour lifetime, renewed by every successful refresh, not a serialized object, so a class change cannot break a cached value.

**A copy older than an hour is not used.** After an hour of failed refreshes `current()` tries one
on-demand fetch and otherwise throws `PricingUnavailableException`, so submission is blocked instead
of promising a maximum from stale rates. The refresh job lets exceptions fail the job, so every
failure is logged and recorded in `failed_jobs`.

**`PricingCatalog` is one class with two methods rather than an Action plus a Query.** Refreshing a
cache of provider-owned data is not a domain write, and a Query that falls back to calling an Action
would blur the two. The scheduled job stays a named trigger, as elsewhere in the project.

**Markup lives in `config/pricing.php` (`markup_bps`, default `12500`)**; provider settings live
under `services.pixelforge.key`; the base URL is a constant of the client. The calculator takes the markup as an argument, so
tests never touch config.

## Risks / Trade-offs

- [Rates change between the estimate and submission] → The estimate may use rates up to five minutes old. The recorded charge is always calculated from the generation's own rates, so only the promise "never above the maximum" is briefly at risk; accepted for this assessment.
- [`(string) 0.15` depends on PHP's float-to-string behaviour] → PHP 8 prints the shortest round-trip form; a test pins `0.15 → 1500`, and a variance with more than four decimals is rejected.
- [Scheduler not running] → `current()` fetches on demand when the copy is missing or over an hour old, so a stopped scheduler costs one request per hour, not a broken app.
