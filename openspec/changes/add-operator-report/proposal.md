## Why

The app records what each generation cost the operator and what the user was charged, but nobody
can see those figures together or check them against Pixelforge. The operator needs a report that
shows the money per day and user, and proves the provider costs are the ones Pixelforge billed.

## What Changes

- A page, `/operator/report`, open only to operators. Operators are the users whose ids are listed in the environment (`OPERATOR_USER_IDS`).
- The report lists, for each UTC day and each user, the succeeded generations finished that day: how many, the user charges, the provider costs and the profit. Each day has a total, and the period has a total.
- The period defaults to the last 30 days and can be changed, up to 92 days — the same rules as Pixelforge's usage report.
- Reconciliation: for each day and for the period, the report shows our provider cost, the cost Pixelforge reports for the same day, and whether they match. The provider's figures are fetched fresh on every view and loaded after the page, so our own figures are shown even when Pixelforge is slow or unavailable.
- Every amount in the report is exact, to nine decimals.
- The report says how many generations are unfinished right now, because one finished at the provider and not yet recorded here explains a mismatch for today.
- The report lists generations whose user charge exceeded the estimate's maximum, as promised in `docs/DECISIONS.md`.
- **BREAKING**: users can no longer delete their accounts, and the database refuses to delete a user who has generations. Deleting a user used to delete their generations, which would erase provider costs already paid and make reconciliation impossible. The two tests of account deletion are removed with the feature.

Out of scope: matching individual generations against the provider's list, comparing token counts,
paginating the report, and exporting it.

## Capabilities

### New Capabilities

- `operator-report`: who can see the report, what it lists and for which period, reconciliation with the provider's usage, and the guarantee that recorded generations cannot disappear with their user.

### Modified Capabilities

- `pricing`: a markup below ×1 is refused, so that profit is never negative.

`generation-state` and `generation-history` are used as they are.

## Impact

- New route `GET /operator/report`; removed route `DELETE /settings/profile`.
- New code: a gate and its configuration, a controller and form request, a query that builds the report and one that reconciles it, in a new `app/Queries` folder, a `usage` method on `PixelforgeClient` with its data object, `Money::minus()`.
- New frontend: one page; a link in the sidebar and the header for operators; the "Delete account" block and its component are removed.
- `HandleInertiaRequests` shares whether the user is an operator.
- The generations migration: the user foreign key becomes `restrictOnDelete`, and `completed_at` gets an index. The migration is edited in place — no database has been deployed yet.
- `.env.example` and the README gain `OPERATOR_USER_IDS`.
- `GLOSSARY.md` (Profit, Operator report, Reconciliation) and `docs/DECISIONS.md` (account deletion) are already updated.
- `EstimateGeneration` refuses a markup below ×1; `GenerationData` refuses a succeeded provider state without a completion time, instead of recording the moment it happened to arrive.
- Tests: the provider's key and webhook secret are blank in `phpunit.xml`, and stray HTTP requests are refused for every feature test.
- No new dependencies.
