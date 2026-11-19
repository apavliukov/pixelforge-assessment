# operator-report Specification

## Purpose

Shows the operator, per day and user, what users were charged, what the provider billed and the
profit — and proves the provider costs are right by comparing them with what the provider reports.

## Requirements

### Requirement: Only operators see the operator report

The system SHALL serve the operator report only to signed-in users whose identifier is in the
configured list of operator user identifiers. Every other signed-in user SHALL be refused, and a
guest SHALL be sent to sign in. A user's email SHALL play no part: registration is open and emails
are not verified, so an email proves nothing about who holds the account. Every page SHALL tell the
browser whether the current user is an operator, so that the link to the report is offered only to
operators.

#### Scenario: An operator opens the report

- **WHEN** a user whose identifier is in the list of operators requests the report
- **THEN** the report is shown

#### Scenario: Another user requests the report

- **WHEN** a signed-in user whose identifier is not in the list requests the report
- **THEN** the request is refused as forbidden

#### Scenario: A guest requests the report

- **WHEN** a guest requests the report
- **THEN** they are redirected to sign in

#### Scenario: No operators are configured

- **WHEN** the list of operators is empty
- **THEN** every user is refused

#### Scenario: Taking an operator's email

- **WHEN** an operator changes their email and another user registers with the old one
- **THEN** the first is still an operator and the second is not

#### Scenario: Pages know who is an operator

- **WHEN** an operator and another user each open the generation page
- **THEN** the page data marks the first as an operator and the second as not

### Requirement: The report lists money per UTC day and user

The system SHALL list, for each UTC day in the period and each user, the succeeded generations whose
completion time falls on that day: their number, the sum of their user charges, the sum of their
provider costs, and the profit, which is the first sum minus the second. The day SHALL be taken from
the completion time, not the submission time. Failed and unfinished generations SHALL NOT be listed.

Days SHALL be ordered from the most recent. Each day SHALL carry its totals across users, and the
report SHALL carry the totals of the period. A user SHALL be identified by name and email. Every
amount SHALL be given exactly, to nine decimals.

#### Scenario: One generation

- **WHEN** the period holds one succeeded generation with a provider cost of 373,875,000 nanodollars and a user charge of 467,343,750
- **THEN** its day lists one generation for its user with user charge `0.467343750`, provider cost `0.373875000` and profit `0.093468750`

#### Scenario: Two users on one day

- **WHEN** two users each have succeeded generations completed on the same day
- **THEN** the day has one row per user and totals equal to the sums of the two rows

#### Scenario: One user on two days

- **WHEN** a user has generations completed at `23:59:59` UTC on one day and `00:00:00` UTC on the next
- **THEN** each generation is listed under its own day

#### Scenario: Submitted on one day, completed on the next

- **WHEN** a generation is submitted before midnight UTC and completed after it
- **THEN** it is listed under the day it was completed

#### Scenario: Failed and unfinished generations

- **WHEN** the period holds a failed generation and a processing one
- **THEN** neither is listed and neither adds to any total

#### Scenario: Nothing in the period

- **WHEN** no succeeded generation was completed in the period
- **THEN** the report has no days and its totals are zero

### Requirement: The operator chooses the period

The system SHALL report on a period given by a start date and an end date, both inclusive and both
UTC days. Without them the period SHALL be the 30 days ending today in UTC, and the operator SHALL
be taken to the address that names both dates, so that everything loaded for the page means the
same days. A period longer than 92 days, a period ending in the future, an end date before the
start date, or a value that is not a date SHALL be refused. The error SHALL be attached to the end
date when the operator gave one and to the start date otherwise, and SHALL be shown on the report
for the default period wherever the request came from.

#### Scenario: Default period

- **WHEN** an operator opens the report on 2026-11-19 without dates
- **THEN** they are taken to the report for 2026-10-21 to 2026-11-19

#### Scenario: Chosen period

- **WHEN** an operator asks for 2026-11-01 to 2026-11-03
- **THEN** generations completed on those three days are listed and those completed on 2026-10-31 or 2026-11-04 are not

#### Scenario: Period too long

- **WHEN** an operator asks for a period of 93 days
- **THEN** the request is refused with an error on the end date

#### Scenario: End before start

- **WHEN** the end date is earlier than the start date
- **THEN** the request is refused with an error on the end date

#### Scenario: End in the future

- **WHEN** an operator asks on 2026-11-19 for a period ending on 2026-11-20
- **THEN** the request is refused with an error on the end date

#### Scenario: Only a start date, too long ago

- **WHEN** an operator asks on 2026-11-19 for a period starting on 2026-01-01 without an end date
- **THEN** the request is refused with an error on the start date

#### Scenario: A refused period opened directly

- **WHEN** an operator opens the report's address with a period that is refused
- **THEN** they see the report for the default period with the error

### Requirement: Provider costs are reconciled with the provider's usage report

The system SHALL compare, for each day of the period and for the period as a whole, the provider
cost and number of generations it recorded with the cost and number the provider reports for the
same days. A day SHALL be marked as matching only when both the cost, compared exactly to nine
decimals, and the number of generations are equal. Otherwise it SHALL be marked as not matching
and carry the difference in cost. A day the provider reports usage for SHALL be reconciled even when
the report lists no generations for it.

The provider's figures SHALL be requested anew each time the report is viewed. The report's own
figures SHALL be shown without waiting for them, and SHALL remain available when the provider's
figures cannot be obtained, in which case the report SHALL say that they are unavailable.

#### Scenario: Costs match

- **WHEN** we recorded two generations costing `0.169080000` on a day and the provider reports two generations and `0.169080000` for it
- **THEN** the day is marked as matching

#### Scenario: The provider billed more

- **WHEN** we recorded 8 generations costing `0.758660000` on a day and the provider reports 10 generations and `0.788892500`
- **THEN** the day is marked as not matching, with a difference of `0.030232500`

#### Scenario: Same cost, different number

- **WHEN** the costs for a day are equal and the numbers of generations differ
- **THEN** the day is marked as not matching

#### Scenario: A day only the provider knows

- **WHEN** the provider reports usage for a day on which we recorded no succeeded generation
- **THEN** that day is reconciled with our cost as zero and is marked as not matching

#### Scenario: Period totals

- **WHEN** every day of the period matches
- **THEN** the period is marked as matching, with both totals shown

#### Scenario: The provider is unavailable

- **WHEN** the provider's usage report cannot be obtained
- **THEN** the report still lists our figures and says the provider's are unavailable

#### Scenario: The same period is asked of the provider

- **WHEN** the report is viewed for 2026-11-01 to 2026-11-03
- **THEN** the provider is asked for its usage report from 2026-11-01 to 2026-11-03

### Requirement: The report explains what can make today differ

The system SHALL state how many generations, across all users, are unfinished at the moment the
report is viewed.

#### Scenario: Unfinished generations exist

- **WHEN** two generations are unfinished
- **THEN** the report carries the number two

### Requirement: Charges above the estimate are listed

The system SHALL list the succeeded generations completed in the period whose user charge exceeds
the maximum of their estimate, each with its user, the estimate's maximum, the user charge and the
excess.

#### Scenario: A charge above the estimate

- **WHEN** a generation estimated at no more than `0.086250000` was charged `0.090000000`
- **THEN** it is listed with an excess of `0.003750000`

#### Scenario: No such generations

- **WHEN** every user charge in the period is within its estimate
- **THEN** the list is empty

### Requirement: Recorded generations cannot disappear with their user

The system SHALL NOT offer users a way to delete their account, and SHALL refuse to delete a user
who has generations.

#### Scenario: Deleting an account

- **WHEN** a signed-in user asks to delete their account
- **THEN** there is no such operation and the user and their generations remain

#### Scenario: Deleting a user who has generations

- **WHEN** a user who has a generation is deleted directly in the database
- **THEN** the deletion is refused and the generation remains
