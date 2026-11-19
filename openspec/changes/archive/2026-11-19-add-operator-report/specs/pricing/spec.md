## ADDED Requirements

### Requirement: The markup is never below one

The system SHALL refuse to estimate, and therefore to record, a generation while the configured
markup is below ×1 (10,000 basis points), and SHALL report this as a defect of the application
rather than as pricing being unavailable. A user charge is therefore never below its provider cost.

#### Scenario: Markup below one

- **WHEN** the markup is configured as 9,999 basis points and an estimate is requested
- **THEN** no estimate is produced and the error names the smallest allowed markup

#### Scenario: No markup

- **WHEN** the markup is configured as exactly 10,000 basis points
- **THEN** estimates are produced and the user charge equals the provider cost
