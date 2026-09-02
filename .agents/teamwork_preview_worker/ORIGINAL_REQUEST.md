## 2026-07-17T04:19:23Z
You are the Worker subagent (teamwork_preview_worker). Your task is to implement the code optimizations and transaction safety improvements requested by the Reviewer.

Specifically, implement the following changes:

1. N+1 Query Optimizations:
   - In `app/Console/Commands/BookingReminderCommand.php`, eager-load `ticket.subUnit.unit.admins` in the main query using `with()`, and access the unit admins in memory inside the loop rather than querying the database for each booking.
   - In `app/Console/Commands/PendingTicketReminderCommand.php`, eager-load `subUnit.unit.admins` in the main query using `with()`, and access the unit admins in memory inside the loop.
   - In `app/Console/Commands/CsatReminderCommand.php`, query all database notifications matching `CsatReminderNotification::class` and the solved ticket IDs in bulk *before* the loop. In PHP, group them by ticket ID, and perform count and latest-sent checks in memory inside the loop.

2. Decouple Notifications from Database Transactions:
   - In `app/Console/Commands/CheckSlaCommand.php`, do not call `$admin->notify()` inside the `DB::transaction()` block. Instead, collect the tickets and breach types that require notification inside the transaction, and dispatch the notifications *after* the transaction block has successfully completed/committed.
   - In `app/Console/Commands/PendingTicketReminderCommand.php`, do not call `$admin->notify()` inside the transaction. Collect them and dispatch after the transaction block.
   - In `app/Console/Commands/SnoozeCheckCommand.php`, do not dispatch notifications inside the transaction block. Collect them and dispatch after the transaction block.

3. Phone Number Sanitization:
   - In `app/Channels/WhatsAppChannel.php`, sanitize phone numbers by stripping all non-digit characters (e.g. `preg_replace('/\D/', '', $phoneNumber)`) before running the normalization logic (like replacing leading '0' with '62').

4. Unique Constraint on `admin_unit` Pivot Table:
   - Modify the migration `database/migrations/2026_07_17_120000_create_admin_unit_table.php` to add a unique composite index `$table->unique(['admin_id', 'unit_id']);`. Roll back and re-migrate (or migrate:fresh) to apply the changes.

5. Queue payload serialization:
   - Add `use \Illuminate\Queue\SerializesModels;` (and import the trait) to `app/Notifications/SlaEscalationNotification.php` and `app/Notifications/PendingTicketReminderNotification.php`.

6. Verification:
   - Run `php artisan test` to verify the unit test suite passes.
   - Run `php artisan simulate:sla-and-reminders` to verify the E2E simulation works without exceptions.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A Forensic Auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Please report back when all refactoring is complete, unit tests pass, and details are written in your handoff.md.
