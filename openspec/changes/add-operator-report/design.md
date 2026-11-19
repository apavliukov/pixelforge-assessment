## Context

Every succeeded generation already carries `provider_cost`, `user_charge` and `completed_at`, set
once by `ApplyProviderState` (`completed_at` is the provider's own completion time). `Money` gives
the exact nine-decimal string. `PixelforgeClient` wraps the provider's API. This change adds a
read-only page over those columns, one more provider call, and removes account deletion.

`GET /v1/usage` takes `start_date` and `end_date` (UTC days, inclusive, at most 92 days, default
the last 30) and returns one row per day, including empty days — `date`, `generations`, four token
counts and `cost_usd` as a nine-decimal string — plus a `total`. It counts only succeeded
generations, on the day they completed, and reports each API key separately.

## Goals / Non-Goals

**Goals:**

- The numbers an operator reads come from one SQL aggregation over integer nanodollars; nothing is summed in the browser.
- The page shows our figures even when the provider is slow or down.
- Recorded provider costs cannot be lost.

**Non-Goals:**

- Matching individual generations against the provider's list; comparing token counts; pagination; export; caching of the provider's figures; roles.

## Decisions

### Layout of the code

| Piece                                            | Role                                                                                             |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| `config/app.php`                                 | `operator_user_ids`: `OPERATOR_USER_IDS` split on commas, as integers                            |
| gate `viewOperatorReport` (`AppServiceProvider`) | true when the user's id is in that list                                                          |
| `OperatorReportController@show`                  | renders `operator/Report` with the period, the report, and the reconciliation as a deferred prop |
| `OperatorReportRequest`                          | validates `start_date` and `end_date`, and gives the period with its defaults                    |
| `OperatorReportQuery`                            | one grouped query → `OperatorReport` (days → user rows, day totals, period totals)               |
| `ReconciliationQuery`                            | `OperatorReport` + the provider's usage → per-day and period comparison                          |
| `PixelforgeClient::usage()`                      | `GET /v1/usage` → `UsageReportData` (days of date, generations, cost as `Money`)                 |
| `Money::minus()`                                 | profit and differences; refuses a negative result, like the rest of `Money`                      |

Routes, under `auth`:

```
GET /operator/report   operatorReport.show   can:viewOperatorReport
```

`OperatorReportQuery` and `ReconciliationQuery` are reads, so they live in a new `app/Queries`
folder rather than among the actions, which write. The data objects go in
`app/Dto/OperatorReport/`, next to `app/Dto/Pricing/`. The list of operator ids is one value and
goes into `config/app.php` as `app.operator_user_ids` instead of a file of its own.

### Operators come from the environment, through a gate

Decided in `docs/DECISIONS.md`, which this change corrects from emails to user ids: with open
registration and no email verification, whoever registers an operator's email first would be an
operator. An id exists only once the account does, so the list can only name real accounts. The gate is the only place that knows how an operator is
recognised. `HandleInertiaRequests` shares `auth.isOperator` (the gate's answer) so the sidebar can
show the link; the route middleware stays the real check.

### One grouped query

```sql
select completed_at::date as day, user_id,
       count(*), sum(provider_cost), sum(user_charge)
from generations
where status = 'succeeded' and completed_at >= :start and completed_at < :end_plus_one_day
group by 1, 2
```

`completed_at` is stored in UTC, so the cast is the UTC day. Sums of `bigint` nanodollars are exact;
Postgres returns them as `numeric`, read as integers into `Money`. Users are loaded in one query by
the ids found. Day and period totals are added up in PHP from the rows. Profit is
`user charges − provider costs` per row and per total; with a markup of at least ×1 it is never
negative.

`completed_at` gets an index. Rows for at most 92 days fit in one response: the report is not
paginated on the server, because a day's totals and its reconciliation need every user of that day.
Instead the page collapses each day to its total line and shows the users on demand, so 92 days are
92 lines however many users there are. A report of up to 30 user rows opens fully expanded: the
rows per user are what the report is for.

### Reconciliation is a deferred prop

`Inertia::defer()` makes the page render with our figures and fetch `reconciliation` in a second
request. That request calls `PixelforgeClient::usage()` for the same period. The other props are
closures, so that second request builds the report once, for the comparison, and nothing else. If it throws
(`ConnectionException`, `RequestException`, an unexpected body), the prop is `null` and the page
says the provider's figures are unavailable. Nothing is cached: a reviewer who has just generated an
image must see it.

`ReconciliationQuery` walks the union of the provider's days with usage and our days, so a day
only the provider knows still appears. A day matches when the two `Money` values are equal and the
generation counts are equal. The difference is the absolute difference of the two costs, with a
flag saying which side is higher, because `Money` does not hold negative amounts.

The page shows reconciliation in each day's total row and in the period total: ours, the
provider's, and "matches" or the difference. A day that only the provider knows gets a total row
with no user rows.

### The period

`OperatorReportRequest` rules: both `nullable|date_format:Y-m-d`; `end_date` is
`after_or_equal:start_date` and at most 91 days after it. Defaults: `end_date` = today in UTC,
`start_date` = `end_date` minus 29 days. When only one is given, the other is derived the same way.
The page has two native date inputs in a `GET` form.

### What the browser receives

Plain arrays with amounts as nine-decimal strings prefixed by the page (`$0.758660000`): the
operator page never rounds. Shapes are written by hand in `resources/js/types/operatorReport.ts`,
like the generation types.

- `period`: `{ start, end }`
- `report`: `{ days: [{ date, rows: [{ user: { name, email }, generations, userCharge, providerCost, profit }], totals }], totals }`
- `reconciliation` (deferred, nullable): `{ days: { [date]: { ours, provider, generations: { ours, provider }, matches, difference, providerIsHigher } }, total: { … } }`
- `unfinishedCount`
- `aboveEstimate`: `[{ user, completedAt, estimateMax, userCharge, excess }]`

### Account deletion is removed

Decided in `docs/DECISIONS.md`. Removed: the `profile.destroy` route, `ProfileController@destroy`,
`ProfileDeleteRequest`, `DeleteUser.vue` and its use in `settings/Profile.vue`, and the two tests of
deletion in `ProfileUpdateTest`. The `generations.user_id` foreign key changes from
`cascadeOnDelete` to `restrictOnDelete` in the original migration, which is safe because no
database has been deployed; local databases are rebuilt with `migrate:fresh`.

## Risks / Trade-offs

- [Today can show a mismatch for a few seconds while a finished generation's state is on its way] → the report shows the number of unfinished generations next to the explanation; the next view is correct.
- [The provider counts each API key separately, so a database that was reset shows a lasting mismatch] → true for the local key already (three generations made before a reset); the production key is used only by the deployed app, and its database is never reset.
- [A mismatch does not say which generation is missing] → out of scope; noted in the README as a next step.
- [One provider call per view, against a limit of 60 requests a minute shared with generations] → the report is opened by an operator by hand; a throttled call shows "unavailable" and the next view retries.
- [Editing a migration in place] → acceptable only because nothing is deployed; after the first deploy any schema change is a new migration.
