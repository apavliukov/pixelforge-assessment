# demo-access Specification

## Purpose

Lets anyone who opens the deployed app look around as a user and as an operator without being given
credentials — and only while that is deliberately switched on.

## Requirements

### Requirement: Demo accounts are created with the app

The system SHALL create three demo accounts when it is seeded: two demo users and one demo operator,
each with a name that says what it is and a password that is random and recorded nowhere. A user
SHALL be a demo account only because seeding made it one, never because of its email. Seeding again
SHALL NOT create duplicates or change a demo account's password. Where someone already holds a demo
account's email, seeding SHALL leave that account untouched and SHALL NOT create the demo account.
Seeding SHALL NOT create generations.

#### Scenario: First seeding

- **WHEN** an empty database is seeded
- **THEN** it holds exactly three users: two demo users who are not operators and one demo operator

#### Scenario: Seeding again

- **WHEN** a seeded database is seeded again
- **THEN** it still holds three users, with the same passwords as before

#### Scenario: Other users exist

- **WHEN** a database that already holds a registered user is seeded
- **THEN** the three demo accounts are added and the registered user is unchanged and not an operator

#### Scenario: The demo operator's email is already taken

- **WHEN** a database in which someone registered with the demo operator's email is seeded
- **THEN** that account is unchanged, is neither an operator nor a demo account, and no demo operator is created

#### Scenario: No generations

- **WHEN** a database is seeded
- **THEN** it holds no generations

### Requirement: A demo account is one click away while demo sign-in is on

While demo sign-in is switched on, the system SHALL sign a visitor in as the demo account they
choose, without a password. Choosing the demo operator SHALL lead to the operator report; choosing a
demo user SHALL lead to the generation page. While demo sign-in is switched off, the same request
SHALL be answered as not found and SHALL sign nobody in. Demo sign-in SHALL be off unless configured on by a value that clearly means on (`true`, `1`, `on`,
`yes`); any other value, such as `off` or `no`, SHALL leave it off. It SHALL be limited in how often
one visitor can use it.

#### Scenario: Signing in as a demo user

- **WHEN** demo sign-in is on and a visitor chooses a demo user
- **THEN** they are signed in as that demo user and taken to the generation page

#### Scenario: Signing in as the demo operator

- **WHEN** demo sign-in is on and a visitor chooses the demo operator
- **THEN** they are signed in as the demo operator and taken to the operator report

#### Scenario: Demo sign-in is off

- **WHEN** demo sign-in is off and a visitor asks to be signed in as a demo account
- **THEN** the request is answered as not found and the visitor stays a guest

#### Scenario: The switch is set to a value that means off

- **WHEN** demo sign-in is configured as `off`, `no`, `0`, `false` or left empty
- **THEN** demo sign-in is off

#### Scenario: An account that is not a demo account

- **WHEN** demo sign-in is on and a visitor asks for an account that is not one of the three
- **THEN** the request is answered as not found

#### Scenario: The demo accounts have not been created

- **WHEN** demo sign-in is on and the database has not been seeded
- **THEN** the request is answered as not found and the visitor stays a guest

#### Scenario: Someone holds a demo account's email

- **WHEN** demo sign-in is on and a visitor chooses a demo account whose email belongs to a user who registered with it
- **THEN** the request is answered as not found and that user's account is not opened

#### Scenario: Too many attempts

- **WHEN** one visitor uses demo sign-in more often in a minute than the limit allows
- **THEN** further attempts in that minute are refused as too many requests

### Requirement: The sign-in page offers the demo accounts while demo sign-in is on

While demo sign-in is switched on, the sign-in page SHALL list the demo accounts that exist, each
with what the visitor will see as that account, and SHALL leave out one that has not been created,
so that no button leads nowhere. While it is off, the page SHALL list none. The ordinary
sign-in form and the link to registration SHALL be present in both cases.

#### Scenario: Demo sign-in is on

- **WHEN** a guest opens the sign-in page while demo sign-in is on
- **THEN** the page lists the two demo users and the demo operator

#### Scenario: Demo sign-in is off

- **WHEN** a guest opens the sign-in page while demo sign-in is off
- **THEN** the page lists no demo accounts

#### Scenario: A demo account that was not created

- **WHEN** demo sign-in is on and one demo account does not exist because someone else holds its email
- **THEN** the page lists the other two only

#### Scenario: Nothing has been seeded

- **WHEN** demo sign-in is on and no demo account has been created
- **THEN** the page lists no demo accounts

#### Scenario: A password does not open a demo account

- **WHEN** a visitor submits the ordinary sign-in form with a demo account's email and any password
- **THEN** they are not signed in

### Requirement: A demo account cannot be taken over

The system SHALL refuse to change a demo account's name or email, and SHALL tell the profile page
that the account cannot be changed. A demo account's password SHALL remain unknown: it cannot be
changed without the current one. An ordinary user's profile SHALL remain changeable.

#### Scenario: Changing a demo account's email

- **WHEN** someone signed in as a demo account submits a new name and email for it
- **THEN** the request is refused as forbidden and the account keeps its name and email

#### Scenario: The profile page of a demo account

- **WHEN** someone signed in as a demo account opens the profile page
- **THEN** the page is told the account cannot be changed

#### Scenario: Changing a demo account's password

- **WHEN** someone signed in as a demo account tries to set a new password
- **THEN** the request is refused, because the current password is not known

#### Scenario: An ordinary user

- **WHEN** a registered user opens the profile page
- **THEN** the page is told the account can be changed
