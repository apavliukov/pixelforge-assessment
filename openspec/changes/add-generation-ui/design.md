## Context

`SubmitGeneration` already records a generation and queues it; `ApplyProviderState` keeps it
current; `Money::format()` and `toDecimalString()` give the display and exact strings. This change
adds only the HTTP and browser layers on top. The starter kit provides auth, the sidebar layout,
shadcn-vue components, Wayfinder route helpers and Inertia v3.

Constraints carried over from `docs/DECISIONS.md`: only the backend calculates prices; the frontend
receives ready-made strings and does no money arithmetic; history updates through Inertia partial
reloads, no WebSockets.

## Goals / Non-Goals

**Goals:**

- Controllers stay triggers: validate, call an action, shape the answer.
- Every string a user reads about money, status or failure is produced on the backend, so it is covered by Pest tests.
- The form keeps working while history refreshes underneath it.

**Non-Goals:**

- Vue component tests; a page per generation; optimistic UI; retry or delete buttons.

## Decisions

### Layout of the code

| Piece                                                 | Role                                                                                                                               |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `GenerationsController@index`                         | renders `generations/Index` with the history, the unfinished count and the first estimate                                          |
| `GenerationsController@store`                         | calls `SubmitGeneration`, redirects to `generations.index`                                                                         |
| `GenerationEstimatesController@store`                 | calls `EstimateGeneration`, returns JSON                                                                                           |
| `StoreGenerationRequest`, `EstimateGenerationRequest` | validation; share rules through `App\Concerns\GenerationValidationRules`, next to the starter kit's `PasswordValidationRules`      |
| `EstimateGeneration` (action)                         | catalog + calculator + configured multiplier → `PriceEstimate`; `SubmitGeneration` calls it instead of repeating those three steps |
| `GenerationResource`                                  | the only shape a generation takes in the browser                                                                                   |
| `GenerationStatus::label()`                           | the five status texts                                                                                                              |

Routes, under `auth` (no `verified`: registration needs no email verification):

```
GET  /generations            generations.index
POST /generations            generations.store
POST /generations/estimate   generationEstimates.store   throttle:120,1
GET  /                       redirect to /generations
```

No policy: every query starts from `$request->user()->generations()`, and there is no route that
takes a generation identifier.

### The estimate is a JSON endpoint called with `useHttp`, not a partial reload

A partial reload would put the estimate in page props, but each one is an Inertia visit: it cancels
or is cancelled by the history poll and by a form submission in flight. `useHttp` (Inertia v3) is a
plain request outside the visit cycle. The form debounces 300 ms and aborts the previous request
before sending the next, so only the answer for the latest values is ever shown.

The first estimate comes as a page prop, so the price is visible without a round trip. On a history
refresh it is not recalculated (`only: ['generations', 'unfinishedCount']`; the prop is a closure).

Response: `{ "min": Amount, "max": Amount }` with `Amount = { "formatted": "$0.4674", "exact": "0.467343750" }`.
`Money` gains one method returning that pair; the resource uses it too.

Failures: `422` with field errors (standard); `503` with a message when pricing is unavailable;
`429` from the throttle. The form shows a dash for the price on `422`, the pricing banner on `503`,
and keeps the last estimate on `429` or a network error.

Alternative considered: estimate in the browser. Rejected in `docs/DECISIONS.md` — two
implementations of the price, and JavaScript counts characters differently.

### The estimate request is looser than the submission in two ways

`prompt` is `required` to submit and `nullable` to estimate (the framework turns an empty string
into `null`; the controller reads it as `''`). And an estimate takes `reference_image_count`
(0 to 4) instead of the URLs: only the number affects the price, and validating a half-typed URL
would replace the price with a dash on every keystroke. The form counts its non-blank fields.

The rest is shared through the trait: the prompt's `max:4000` (which counts Unicode characters) and
the two enums. The URL rules — `array|list|max:4` of `url:http,https|max:2048` — belong to the
submission only. Whitespace is trimmed by the framework before both, so the estimate and the
submission count the same characters.

### Refusals are form errors under one key

`store` catches `TooManyUnfinishedGenerationsException` and `PricingUnavailableException` and
rethrows a `ValidationException` on the key `generation`, which the form shows next to the submit
button. Using the validation channel keeps the form's values and needs no flash plumbing.

The page also receives `unfinishedCount` and `maxUnfinished`, and `estimate: null` when pricing is
unavailable, so the button is disabled with the reason shown before the user tries. The server check
stays authoritative: another tab can submit in between.

### Texts live on the backend

`GenerationResource` returns `status`, `status_label`, `failure_reason` (null unless failed),
`estimate` (`min`, `max`), `charge` (null while unfinished), `image_url`, `image_expired`,
`submitted_at` (ISO 8601, formatted in the browser with `Intl.DateTimeFormat`), and the request
fields. It never includes `provider_cost`, the multiplier, `error_message`, `provider_id` or `rates`.

The failure reason is a `match` on the stored `error_code` string inside the resource, with a
default arm. It is not a method on `FailureCode`, because the code of an accepted generation is the
provider's own open set (`generation_failed` today), not that enum.

`image_url` is given only for a succeeded generation whose `image_url_expires_at` is in the future.
The page can outlive the link, so `<img>` also switches to the placeholder on `@error`.

### History refresh

`usePoll(2000, { only: ['generations', 'unfinishedCount'] }, { autoStart: false })`, started and
stopped by a watcher on "any listed generation is not final". The query is the user's 15 newest
rows by `(user_id, created_at)`; the implementation checks that an index covers it.

Submitting uses `useForm().post()` with `preserveState` and `preserveScroll`, so the redirect back
to the same component keeps the form's values (the agreed behaviour: users iterate on a prompt).
The redirect drops any `page` query, which puts the new generation in view.

### Removing the starter pages

`Welcome.vue` and `Dashboard.vue` go; `/` redirects; `config('fortify.home')` becomes
`/generations`; the sidebar and header link to `generations.index`. `DashboardTest` and
`ExampleTest` are replaced by the page's own tests (guest redirect, root redirect), and the three
auth tests that assert a redirect to `dashboard` are updated.

### Server-side rendering is off in tests

`config/inertia.php` had SSR hard-coded on. With the Vite dev server running, a page test then asks
it to render, which `Http::preventStrayRequests()` rightly refuses. The setting now reads
`INERTIA_SSR_ENABLED` (default on) and `phpunit.xml` turns it off.

## Risks / Trade-offs

- [A 2-second poll per open page] → one indexed query of 15 rows, only while something is unfinished; generations finish in seconds to a couple of minutes.
- [A new generation shifts rows across pages while the user is on page 2] → accepted; submission always returns to page 1.
- [The estimate shown and the one recorded can differ if pricing is refreshed in between] → both are computed by the same action; the recorded one is what counts, and the history shows it immediately.
- [Frontend behaviour is not covered by automated tests] → all texts and amounts come from tested backend code; the page is verified by hand in the browser, including with the real API.
- [Texts in a PHP `match` rather than translation files] → the app is English only; moving them later is mechanical.
