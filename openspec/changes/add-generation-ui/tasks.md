## 1. Estimating

- [x] 1.1 Add `Money` method returning the `formatted`/`exact` pair with unit tests (467,343,750 → `$0.4674` / `0.467343750`; zero) and verify they pass
- [x] 1.2 Add the `EstimateGeneration` action with a feature test of the documented example, make `SubmitGeneration` use it, and verify the new test and the existing `SubmitGeneration` tests pass

## 2. Estimate endpoint

- [x] 2.1 Add `GenerationValidationRules` and `EstimateGenerationRequest`; write feature tests for every scenario of "The estimate is shown before submission" that concerns the endpoint (documented example, empty prompt, invalid values) plus guest access, and verify they fail
- [x] 2.2 Add `GenerationEstimatesController@store` and the route with `throttle:120,1`; verify the tests from 2.1 pass, and add tests for "Pricing unavailable for an estimate" (503) and "Over the limit" (429) that pass

## 3. Page and submission

- [x] 3.1 Add `GenerationStatus::label()` with a unit test of the five texts and verify it passes
- [x] 3.2 Add `GenerationResource`; write feature tests for the history requirements (own generations only, newest first, 16 → 15 + 1, amounts per status, failure reasons for each code and an unknown one, image fresh/expired/absent, no provider cost, multiplier or provider message in the props) and verify they fail
- [x] 3.3 Add `GenerationsController@index` with the `generations`, `unfinishedCount`, `maxUnfinished` and `estimate` props and the route; verify the tests from 3.2 pass, plus tests that a partial reload of `generations` does not evaluate `estimate` and that `estimate` is null when pricing is unavailable
- [x] 3.4 Add `StoreGenerationRequest` and `GenerationsController@store`; write and pass feature tests for every scenario of "A generation request is validated" and "A refused submission is explained" (with `Queue::fake()`), including the redirect to the first page
- [x] 3.5 Redirect `/` to the page, set `fortify.home`, remove the `dashboard` route; replace `DashboardTest` and `ExampleTest` with tests of the guest redirect and root redirect, update the auth tests asserting the old redirect, and verify the whole suite passes

## 4. Frontend

- [x] 4.1 Add TypeScript types for the page props and the `generations/Index.vue` page with the two-column layout; remove `Welcome.vue`, `Dashboard.vue` and unused `PlaceholderPattern.vue`; point the sidebar and header at the page; verify `npx vue-tsc --noEmit` and `npx vp check` pass on the changed files and the page opens in the browser
- [x] 4.2 Build the form component: prompt with a character counter, size, quality, up to four reference image fields with add and remove, field errors, the debounced and abortable estimate request, the range with its hint and exact values in tooltips, the upper bound on the button, the limit and pricing-unavailable states; verify in the browser that the price follows every field and matches the endpoint's answer
- [x] 4.3 Build the history card and pagination: thumbnail or placeholder (in progress, expired, failed), prompt, parameters, status badge, local time, amounts per status, failure reason; verify in the browser against factory-made generations in every status
- [x] 4.4 Add the conditional poll and verify in the browser that requests stop when every listed generation is final and that typing in the form is not disturbed by a refresh

## 5. Wrap-up

- [x] 5.1 Run Pint and Rector on the changed PHP files, `vendor/bin/sail composer types:check`, `vp check` on the changed frontend files and the full test suite; verify all pass
- [x] 5.2 With the local Pixelforge key and the tunnel running, submit through the page with `[succeed]`, `[fail]`, `[blocked]`, `[overloaded]` and `[slow]` in the prompt and a fourth generation while three are unfinished; verify each card ends in the expected status, text and amount without a manual reload
