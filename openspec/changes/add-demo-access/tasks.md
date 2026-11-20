## 1. Operator mark

- [x] 1.1 Add the `is_operator` migration, the cast and `@property` on `User`, and the `operator` factory state; point the `viewOperatorReport` gate at the column; write tests for "Only operators see the operator report" (operator, other user, guest, no operators) and verify they pass
- [x] 1.2 Write tests that registering and updating the profile with the operator mark in the request leave the user a non-operator, and verify they pass
- [x] 1.3 Remove `operator_user_ids` from `config/app.php` and `OPERATOR_USER_IDS` from `.env.example` and `phpunit.xml`; replace the id-list helper in `OperatorReportTest` with the factory state; verify the whole suite passes

## 2. Demo accounts

- [x] 2.1 Add the `DemoAccount` enum; its three accounts are asserted through the seeder tests
- [x] 2.2 Rewrite `DatabaseSeeder` to create the demo accounts; write tests for every scenario of "Demo accounts are created with the app" and verify they pass
- [x] 2.3 Add the `demo_account` column, cast and `User::isDemoAccount()`; make the seeder write it and skip an account whose key or email is taken; test that someone holding a demo account's email is left alone and is not opened by demo sign-in

## 3. Demo sign-in

- [x] 3.1 Add `demo_login` to `config/app.php`, `DEMO_LOGIN_ENABLED` to `.env.example` (on) and `phpunit.xml` (off); add `DemoLoginController@store` and the route; write tests for every scenario of "A demo account is one click away while demo sign-in is on" and verify they pass
- [x] 3.2 Pass `demoAccounts` to the sign-in page; write tests for "The sign-in page offers the demo accounts while demo sign-in is on" (on, off, a password does not open a demo account) and verify they pass

- [x] 3.3 Refuse profile changes for demo accounts and tell the profile page; write tests for every scenario of "A demo account cannot be taken over" and verify they pass

- [x] 3.4 Parse the switch strictly and list only the demo accounts that exist on the sign-in page, with tests for both

## 4. Frontend

- [x] 4.1 Add the demo buttons to `auth/Login.vue` above the existing form, with the type for the new prop, and the fixed-profile state to `settings/Profile.vue`; verify `npx vue-tsc --noEmit` and `npx vp check` pass on the changed files
- [x] 4.2 In the browser, with demo sign-in on: sign in as each demo account and check the landing page; with it off: check the buttons are gone and the form still works

## 5. Deploy and documents

- [x] 5.1 Run the seeder from `docker/production/start.sh` after the migrations; add it to the local setup in the README and the `setup` script
- [x] 5.2 Update the README: `DEMO_LOGIN_ENABLED` in place of `OPERATOR_USER_IDS` in the environment table; a note that the demo is open to anyone; "What I would do next" opens with replacing the `is_operator` column by roles and permissions (`spatie/laravel-permission`)

## 6. Wrap-up

- [x] 6.1 Run Pint and Rector on the changed PHP files, `vendor/bin/sail composer types:check`, `vp check` on the changed frontend files and the full test suite; verify all pass
- [x] 6.2 Run the new migration and the seeder on the local database without resetting it; verify the three demo accounts exist, the existing users and generations are untouched, and the operator report opens for the demo operator
