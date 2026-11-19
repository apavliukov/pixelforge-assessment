## Why

Generations can be submitted, tracked and priced exactly, but only from code: there is no page. A
user needs to see what a generation will cost before paying for it, submit it, and watch it reach a
final status with the price they were actually charged.

## What Changes

- One page, `/generations`, with the generation form and the user's history side by side.
- The form shows the estimate as a range before submission and updates it when any field changes; the submit button carries the upper bound.
- An endpoint that estimates a not-yet-submitted generation, used by the form. Only the backend calculates prices.
- An endpoint that validates and submits a generation, and explains a refusal: the per-user limit, or pricing being unavailable.
- History: the user's own generations, newest first, 15 per page, each with its status in plain words, the estimate, the actual charge and the image.
- History refreshes itself while any generation on the page is unfinished, and stops when all are final.
- A failed generation shows a reason the user can act on and states that they were not charged. The provider's own message is not shown to users.
- An image whose link has expired shows a placeholder instead of a broken image.
- **BREAKING**: the starter kit's `Welcome` and `Dashboard` pages are removed. `/` redirects to `/generations`, and sign-in lands there. `tests/Feature/DashboardTest.php` and `tests/Feature/ExampleTest.php` are replaced by tests of the new page and redirect.

Out of scope: a page for a single generation, copying images to our own storage, tests of Vue
components, and the operator report.

## Capabilities

### New Capabilities

- `generation-form`: what a user can enter, the estimate shown before submission, submitting, and what the user is told when a submission is refused.
- `generation-history`: which generations a user sees, what is shown for each status, the texts for failures, live refresh, and expired images.

### Modified Capabilities

None. `pricing`, `generation-submission` and `generation-state` are used as they are.

## Impact

- New routes in `routes/web.php`: `GET /generations`, `POST /generations`, `POST /generations/estimate`; `/` becomes a redirect; `dashboard` is removed.
- New code: two controllers, two form requests with shared rules, an API resource, an action that estimates a generation (also used by `SubmitGeneration`), labels on the status enum.
- New frontend: one page with a form component and a history card component; the sidebar and header point to `/generations`.
- Removed: `Welcome.vue`, `Dashboard.vue`, `PlaceholderPattern.vue` if nothing else uses it, and their tests.
- `config/fortify.php`: `home` becomes `/generations`. Auth tests that assert the redirect are updated.
- `GLOSSARY.md`: the term **History**.
- No new dependencies, no schema changes.
