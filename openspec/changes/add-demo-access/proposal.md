## Why

The assignment's submission form takes a repository, a deployed URL and an email — there is nowhere
to tell reviewers how to sign in or where the operator report is. The deployed app has to explain
itself: a reviewer must be able to look around as a user and as an operator without being given
anything.

## What Changes

- Three demo accounts are created with the app: two demo users and a demo operator. Their passwords are random and known to nobody.
- The sign-in page offers one button per demo account. The buttons, and the route behind them, exist only while demo sign-in is switched on (`DEMO_LOGIN_ENABLED`, off by default).
- Signing in as the demo operator lands on the operator report; signing in as a demo user lands on the generation page.
- Registration and the ordinary sign-in form stay, below the demo buttons.
- **BREAKING**: an operator is now a user marked as one in the database (`users.is_operator`), and only the seeder sets the mark. The list of operator ids in the environment (`OPERATOR_USER_IDS`) is removed. Nothing a user can do — registering, changing their profile — makes them an operator.
- The seeder runs on every start of the production container and is safe to repeat. It creates accounts only, never generations: an invented generation would carry a provider cost the provider knows nothing about.
- The starter kit's "Test User" seed is removed.

Out of scope: roles and permissions, forbidding an operator to generate, seeding generations, and
a way to manage operators from the app.

## Capabilities

### New Capabilities

- `demo-access`: the demo accounts, one-click sign-in and the switch that turns it on.

### Modified Capabilities

- `operator-report`: who an operator is — a user marked in the database by the seeder, instead of an id listed in the environment.

## Impact

- New migrations: `users.is_operator`, false by default, and `users.demo_account`, which names the demo account a user is.
- New code: a `DemoAccount` enum naming the three accounts, a controller and route for demo sign-in, an `operator` factory state.
- Changed: `DatabaseSeeder`, the `viewOperatorReport` gate, `config/app.php` (`demo_login` in, `operator_user_ids` out), the sign-in page and its props, `docker/production/start.sh` (runs the seeder), `.env.example`, `phpunit.xml`.
- Tests of operator access use the factory state; the test about taking an operator's email stays, and tests are added that registration and profile changes cannot set the mark.
- README: `DEMO_LOGIN_ENABLED` replaces `OPERATOR_USER_IDS` in the environment table; "What I would do next" opens with replacing the `is_operator` column by roles and permissions (`spatie/laravel-permission`); a note that the demo is open to anyone.
- `GLOSSARY.md` (Demo account) and `docs/DECISIONS.md` (the column, demo sign-in) are already updated.
- No new dependencies.
