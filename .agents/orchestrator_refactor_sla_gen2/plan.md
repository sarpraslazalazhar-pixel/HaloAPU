# Refactoring Plan: SLA Checker and Reminder Systems

This plan outlines the milestones, roles, and procedures for refactoring and fixing the SLA checker and Reminder systems in Halo APU.

## Architecture & Scope
1. **SLA Checker**:
   - `app/Console/Commands/CheckSlaCommand.php`
   - `app/Services/SlaCalculator.php`
   - `app/Models/TicketSlaTracking.php`
   - Requirements: Ensure correct identification of SLA breaches based on the `priority` column, update ticket status/sla tracking, and trigger notifications (`SlaEscalationNotification.php`) in database transactions.
2. **Reminder Systems**:
   - `app/Console/Commands/BookingReminderCommand.php`
   - `app/Console/Commands/CsatReminderCommand.php`
   - `app/Console/Commands/PendingTicketReminderCommand.php`
   - `app/Console/Commands/SnoozeCheckCommand.php`
   - Requirements: Audit query correctness, ensure WhatsApp and database notification dispatch works, handle failures gracefully, and use transactions where necessary.
3. **Simulation / Verification**:
   - Create a dedicated test simulation (e.g., an Artisan command or a test script) that artificially triggers SLA conditions and reminders.
   - Run simulation and verify that tickets transition to breached status and reminders are generated without errors, with output logs.

## Milestones

| Milestone | Name | Objective | Target Files | Status |
|---|---|---|---|---|
| M1 | Setup & Analysis | Analyze codebase, identify bugs/gaps in SLA check and reminder commands, plan code changes. | all Console Commands, SlaCalculator, Notifications | DONE |
| M2 | SLA Checker Refactoring | Refactor CheckSlaCommand to handle priority, update status, and escalate within transactions. | CheckSlaCommand, SlaCalculator | DONE |
| M3 | Reminder System Fixes | Review and fix BookingReminder, CsatReminder, PendingTicketReminder, and SnoozeCheck. | Reminder Commands | DONE |
| M4 | Verification Simulation | Create simulation script/command, run simulation, and verify correct state transitions and logs. | Simulation Command/Test | DONE |
| M5 | E2E Audit & Review | Run full suite, run reviewer, challenger, and forensic auditor. Verify layout compliance. | - | IN_PROGRESS (Iteration 2) |

## Verification Plan
1. **Developer Tests**: PHPUnit tests for SLA calculator and commands.
2. **Simulation Script**: An Artisan command or script that mocks current time, inserts dummy tickets/bookings, and runs the SLA/Reminder commands.
3. **Forensic Audit**: Run `teamwork_preview_auditor` to verify that there are no cheating/mock bypass violations.
