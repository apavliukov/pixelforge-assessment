## MODIFIED Requirements

### Requirement: Only operators see the operator report

The system SHALL serve the operator report only to signed-in users who are marked as operators.
Every other signed-in user SHALL be refused, and a guest SHALL be sent to sign in. Only seeding
SHALL mark a user as an operator: registering, signing in and changing one's profile SHALL NOT be
able to, whatever data is sent. Every page SHALL tell the browser whether the current user is an
operator, so that the link to the report is offered only to operators.

#### Scenario: An operator opens the report

- **WHEN** a user marked as an operator requests the report
- **THEN** the report is shown

#### Scenario: Another user requests the report

- **WHEN** a signed-in user who is not marked as an operator requests the report
- **THEN** the request is refused as forbidden

#### Scenario: A guest requests the report

- **WHEN** a guest requests the report
- **THEN** they are redirected to sign in

#### Scenario: No operators are configured

- **WHEN** no user is marked as an operator
- **THEN** every user is refused

#### Scenario: Taking an operator's email

- **WHEN** an operator changes their email and another user registers with the old one
- **THEN** the first is still an operator and the second is not

#### Scenario: Registering as an operator

- **WHEN** a visitor registers and sends the operator mark along with their details
- **THEN** the account is created and is not an operator

#### Scenario: Becoming an operator through the profile

- **WHEN** a signed-in user updates their profile and sends the operator mark along with it
- **THEN** the profile is updated and the user is still not an operator

#### Scenario: Pages know who is an operator

- **WHEN** an operator and another user each open the generation page
- **THEN** the page data marks the first as an operator and the second as not
