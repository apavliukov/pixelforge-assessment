## 1. Money

- [x] 1.1 Write unit tests for `Money` parsing and exact output (spec "Amounts are exact": valid rate, malformed string, nine-decimal string) and verify they fail
- [x] 1.2 Implement `App\Pricing\Money` (`fromDecimalString`, `fromNano`, `plus`, `times`, `toDecimalString`, `isZero`) and verify the tests from 1.1 pass
- [x] 1.3 Add tests for `applyBasisPoints` (worked example, round-up of 1 nanodollar, zero) and `format` (`$0.4674`, `$0.2580`, `$0.00`), implement both, and verify the tests pass

## 2. Provider data

- [x] 2.1 Add `ImageSize` and `ImageQuality` enums with the values from the API docs and verify `vendor/bin/sail artisan test` still passes
- [x] 2.2 Add `TokenRates` and `TokenUsage` DTOs; test that `TokenRates::fromArray` yields 5,000 / 10,000 / 40,000 / 12,500 for the documented rates and rejects `"5.0005"`; verify the tests pass
- [x] 2.3 Add `PricingData::fromArray` with validation; test it against the documented `/v1/pricing` payload, a payload without `reasoning`, and the variance `0.15 → 1500` conversion; verify the tests pass

## 3. Calculator

- [x] 3.1 Write tests for `PriceCalculator::providerCost` (both documented examples) and `userCharge`, implement them, and verify the tests pass
- [x] 3.2 Write tests for `PriceCalculator::estimate` (documented minimum and maximum, no reference images, five emoji, missing quality × size), implement it, and verify the tests pass

## 4. Pixelforge pricing

- [x] 4.1 Add `services.pixelforge.key` and `config/pricing.php` (`markup_bps`); verify `vendor/bin/sail artisan config:show pricing` prints `12500`
- [x] 4.2 Add `PixelforgeClient::pricing()` and a feature test with `Http::fake()` asserting the request URL and the returned `PricingData`; verify the test passes
- [x] 4.3 Add `PricingCatalog` (`current`, `refresh`) with feature tests for every scenario of "Published pricing is kept locally and refreshed on a schedule"; verify the tests pass
- [x] 4.4 Add `RefreshPricingJob`, schedule it every five minutes in `routes/console.php`, and verify `vendor/bin/sail artisan schedule:list` shows it

## 5. Wrap-up

- [x] 5.1 Run `vendor/bin/sail bin pint --dirty --format agent` and `vendor/bin/sail composer types:check`; verify both pass
- [x] 5.2 Run `vendor/bin/sail artisan tinker --execute 'dump(app(App\Pricing\PricingCatalog::class)->current());'` against the real API and verify it returns the live pricing
