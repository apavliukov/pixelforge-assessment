## 1. Access

- [x] 1.1 Add `operator_user_ids` to `config/app.php` and `OPERATOR_USER_IDS` to `.env.example`; define the `viewOperatorReport` gate; write unit or feature tests for the gate (listed id, unlisted, empty list, an operator's email taken by another user) and verify they pass
- [x] 1.2 Share `auth.isOperator` from `HandleInertiaRequests` with a feature test of the scenario "Pages know who is an operator", and verify it passes

## 2. Report

- [x] 2.1 Add `Money::minus()` with unit tests (a difference, zero, refusing a negative result) and verify they pass
- [x] 2.2 Add the `OperatorReport` data objects and the `OperatorReportQuery`; write feature tests for every scenario of "The report lists money per UTC day and user" and verify they pass
- [x] 2.3 Add an index on `completed_at` to the generations migration and verify the report query uses the schema as expected (`migrate:fresh` locally, tests pass)

## 3. Page

- [x] 3.1 Add `OperatorReportRequest`, `OperatorReportController@show` and the route; write feature tests for "Only operators see the operator report" (operator, other user, guest) and "The operator chooses the period" (default, chosen, too long, end before start) and verify they pass
- [x] 3.2 Add `unfinishedCount` and `aboveEstimate` to the page with feature tests for both requirements and verify they pass

## 4. Reconciliation

- [x] 4.1 Add `PixelforgeClient::usage()` and `UsageReportData` with tests against a faked response in the documented shape (dates passed as `start_date` and `end_date`, cost parsed exactly) and verify they pass
- [x] 4.2 Add the `ReconciliationQuery` with tests for every scenario of "Provider costs are reconciled with the provider's usage" that concerns the comparison (match, provider higher, same cost and different number, a day only the provider knows, period totals) and verify they pass
- [x] 4.3 Add `reconciliation` to the page as a deferred prop; test that the first response does not call the provider, that loading the deferred prop asks for the report's period, and that a provider failure gives a null prop; verify they pass

## 5. Account deletion

- [x] 5.1 Remove the `profile.destroy` route, `ProfileController@destroy`, `ProfileDeleteRequest`, `DeleteUser.vue` and its use in `settings/Profile.vue`, and the two deletion tests; change the foreign key to `restrictOnDelete`; add a test that deleting a user with a generation is refused and the generation remains; verify the whole suite passes

## 6. Frontend

- [x] 6.1 Add TypeScript types and the `operator/Report.vue` page: the period form, the table grouped by day with user rows, day totals and period totals, exact amounts; add the sidebar link for operators; verify `npx vue-tsc --noEmit` and `npx vp check` pass on the changed files and the page opens in the browser
- [x] 6.2 Add reconciliation to the day and period totals with a loading state, the "unavailable" state, the unfinished-generations note and the list of charges above the estimate; verify each state in the browser

## 7. Wrap-up

- [x] 7.1 Add `OPERATOR_USER_IDS` to the README's environment table and the per-generation reconciliation to "What I would do next"
- [x] 7.2 Run Pint and Rector on the changed PHP files, `vendor/bin/sail composer types:check`, `vp check` on the changed frontend files and the full test suite; verify all pass
- [x] 7.3 With the local Pixelforge key, open the report as an operator and compare each day with `GET /v1/usage`; verify that generations made after the last database reset reconcile and that the known three from before it show as the difference
