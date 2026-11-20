## Context

Operators are currently the users whose ids are listed in `OPERATOR_USER_IDS`; the gate
`viewOperatorReport` is the only place that knows. Fortify provides registration and sign-in;
`DatabaseSeeder` still creates the starter kit's "Test User"; `docker/production/start.sh` migrates
on every start and does not seed.

The assignment says only "Users sign in" about accounts, and its submission form has no place for
instructions. The decisions behind this change are recorded in `docs/DECISIONS.md` (Access).

## Goals / Non-Goals

**Goals:**

- A reviewer reaches both the generation page and the operator report from the sign-in page, with no credentials.
- Passwordless sign-in exists only where it is switched on, and only for the seeded accounts.
- Nothing a user can send makes them an operator.

**Non-Goals:**

- Roles and permissions; keeping an operator from generating; seeding generations; managing operators in the app.

## Decisions

### Layout of the code

| Piece                                 | Role                                                                                                |
| ------------------------------------- | --------------------------------------------------------------------------------------------------- |
| migration `add_is_operator_to_users`  | `is_operator` boolean, default false                                                                |
| `User`                                | casts `is_operator` to boolean; it is not fillable; factory state `operator()`                      |
| gate `viewOperatorReport`             | `$user->is_operator`                                                                                |
| `DemoAccount` (enum)                  | the three accounts: key, name, email, whether an operator, the line shown on the sign-in page       |
| `DatabaseSeeder`                      | creates each `DemoAccount` that does not exist yet; sets `is_operator`                              |
| `config/app.php`                      | `demo_login`: `DEMO_LOGIN_ENABLED` as a boolean, false by default; `operator_user_ids` is removed   |
| `DemoLoginController@store`           | signs the visitor in as a demo account and redirects                                                |
| `FortifyServiceProvider` (login view) | passes `demoAccounts` to `auth/Login` — the three while demo sign-in is on, an empty list otherwise |

Route, for guests:

```
POST /demo-login/{demoAccount}   demoLogin.store   guest, throttle:10,1
```

`{demoAccount}` is bound to the enum, so a value that is not one of its cases is a 404 before the
controller runs.

### The operator mark is a column only the seeder writes

Recorded in `docs/DECISIONS.md`. `is_operator` is left out of the model's fillable attributes, so
`CreateNewUser` and `ProfileUpdateRequest`, which pass validated input to `create()` and `fill()`,
cannot set it even if the field is sent. The seeder sets it with `forceFill`. The README says
plainly that a boolean is a shortcut for this assessment and that roles and permissions are the
next step.

Removed with the id list: its parsing in `config/app.php`, the `OPERATOR_USER_IDS` lines in
`.env.example`, `phpunit.xml` and the README.

### Demo accounts are an enum

One place names the accounts, and the seeder, the controller and the sign-in page all read it:

| Case       | Name            | Email                  | Operator |
| ---------- | --------------- | ---------------------- | -------- |
| `Ada`      | Ada (demo user) | `ada@example.com`      | no       |
| `Bob`      | Bob (demo user) | `bob@example.com`      | no       |
| `Operator` | Operator (demo) | `operator@example.com` | yes      |

Two users, so that separate histories and the per-user rows of the operator report can be seen.

### The seeder

A user is a demo account when the seeder has written the account's key into `users.demo_account`
(nullable, unique, not fillable). Nothing else recognises one: not the email, which anyone could
have registered first. For each account the seeder checks whether a user with that key or that
email exists; if so it does nothing, otherwise it creates the user with the name, a
`Str::password(40)` password, a verified email, the key and, for the operator, the operator mark.
So the mark and the key are only ever set on an account the seeder itself created. If someone
already holds a demo account's email, that demo account does not exist and its button finds nothing.
The seeder creates nothing else — no generations, because an invented one would carry a provider
cost the provider does not have and the report could never reconcile.

`docker/production/start.sh` runs `php artisan db:seed --force` right after `migrate --force`, on
every start. The demo accounts therefore exist before the web server accepts a request.

### Demo sign-in

`DemoLoginController@store` does, in order: `abort_unless(config('app.demo_login'), 404)`; find the
user by `demo_account`, 404 if the seeder has not created it; `Auth::login()`; regenerate the session;
redirect to `operatorReport.show` for the operator and `generations.index` otherwise.

The switch is read with `FILTER_VALIDATE_BOOLEAN`, so only `true`, `1`, `on` and `yes` turn it on: a
plain cast would have made `off` and `no` mean on. The switch is checked in the controller and not by registering the route conditionally, so that it
is one config value read at request time and can be tested both ways. With the switch off the
route answers exactly as if it did not exist.

Anyone can therefore use the demo accounts while the switch is on. That is the point of the demo
and is bounded by what already exists: at most three unfinished generations per user, and the
provider's own daily limit. The throttle only keeps the endpoint from being hammered.

### A demo account's profile is fixed

Demo accounts are shared. If one could change its email, whoever
changed it could then have a password reset sent to the new address and keep the account, operator
rights included. So `ProfileUpdateRequest::authorize()` refuses a demo account
(`User::isDemoAccount()`, which reads `demo_account`), and `ProfileController@edit`
passes `canUpdate`, on which the page disables the fields and explains why.

The password needs no extra rule: changing it requires the current one, which nobody knows, and a
reset link for a demo account goes to an `example.com` address nobody receives.

### The sign-in page

`auth/Login.vue` gets `demoAccounts: { key, name, description }[]`: the demo accounts that exist,
so a database that was not seeded, or an account whose email someone else holds, shows no dead
button. When the list is not empty the
page shows, above the form, a short line saying this is a demo and one button per account
("Sign in as Ada", "Sign in as the operator"), each a small form posting to `demoLogin.store`
through its Wayfinder helper. Below it, "or sign in with your own account" and the existing form.
The link to registration is unchanged.

### Tests

The `operator()` helper in `OperatorReportTest` becomes `User::factory()->operator()->create()`.
The email-takeover test stays, as it holds for the column as well. New: the seeder (first run, repeated run, alongside
a registered user, an email someone else holds, no generations), demo sign-in (each account and its destination, switch off,
unknown account, unseeded database, throttle, session regenerated), the sign-in page's
`demoAccounts` prop both ways, a password not opening a demo account, and the operator mark not
being settable through registration or the profile.

`phpunit.xml` drops `OPERATOR_USER_IDS` and sets `DEMO_LOGIN_ENABLED` to false, so a developer's
`.env` cannot switch it on for the tests.

## Risks / Trade-offs

- [The deployed demo is open to anyone: they can spend the provider key's quota and read the operator report] → accepted and written down in `docs/DECISIONS.md` and the README; the report holds demo accounts and whoever chose to register; the provider is a simulator.
- [Reviewers share the demo accounts, so they see each other's generations and share the limit of three unfinished] → two demo users, and registration for anyone who wants an account of their own.
- [A passwordless route in the code base] → off by default, 404 when off, limited to the three seeded accounts, covered by tests for the off state.
- [A third decision about operator access in two days] → the gate absorbed each change; `docs/DECISIONS.md` keeps the reasoning for the one that stands.
- [Local databases have users who were operators by id] → they stop being operators; locally the demo operator is used instead.

## Migration Plan

Nothing is deployed, so there is no data to migrate. Locally: run the new migration and the seeder;
`OPERATOR_USER_IDS` in a local `.env` becomes an unused line.
