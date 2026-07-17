# Handoff Report — SLA Checker and Reminder Refactoring

## 1. Observation

- **Step 1: Database Relationships**
  - Created and ran migration `database/migrations/2026_07_17_120000_create_admin_unit_table.php` (created `admin_unit` pivot table with `admin_id` and `unit_id`).
  - Created and ran migration `database/migrations/2026_07_17_120001_add_assigned_admin_id_to_tickets_table.php` (added `assigned_admin_id` foreign key referencing `admins.id` on the `tickets` table).
  - Defined Eloquent relationships:
    - `Admin` model (`app/Models/Admin.php`): Defined belongsToMany `units` (target `Unit`).
    - `Unit` model (`app/Models/Unit.php`): Defined belongsToMany `admins` (target `Admin`).
    - `Ticket` model (`app/Models/Ticket.php`): Defined belongsTo `assignedAdmin` (target `Admin`), added `assigned_admin_id` to `$fillable`.
  - Ran migration via `php artisan migrate`, which applied the new migrations successfully.

- **Step 2: Refactor SLA Escalation Logic**
  - Refactored `app/Console/Commands/CheckSlaCommand.php` to wrap ticket processing in a database transaction per ticket, caught exceptions, and tracked SLA breaches using state changes of `is_response_breached` and `is_resolution_breached` flags.
  - Refactored `app/Notifications/SlaEscalationNotification.php` to accept the breach type (`respon` or `penyelesaian`) and ticket priority. Delivery channels now adjust based on priority (e.g. Tinggi/Kritis sends WhatsApp and DB, others send DB and email).

- **Step 3: Fix Notification Attribute Names**
  - Updated both `SlaEscalationNotification.php` and `PendingTicketReminderNotification.php` to correctly access unit and sub-unit properties via:
    - `$ticket->subUnit?->unit?->nama_unit` (instead of non-existent `nama`)
    - `$ticket->subUnit?->nama_layanan` (instead of non-existent `nama`)

- **Step 4: Refactor Snooze Check Command**
  - In `app/Console/Commands/SnoozeCheckCommand.php`, refactored the query to perform JSON filtering directly in the database using `whereNotNull('read_at')`, `where('data->snoozed', true)`, `whereNotNull('data->snoozed_until')`, `where('data->snoozed_until', '<=', now()->toISOString())`, and `whereNull('data->done_at')`.
  - Re-dispatched the notification classes based on their type, wrapped each execution loop iteration in a database transaction, and updated the old notification to clear snooze flags.

- **Step 5: Fix Unit Tests**
  - Updated `tests/Unit/SlaCalculatorTest.php` and `tests/Unit/SlaCalculatorStressTest.php` to use the `priority` column instead of the deleted `tier` column when setting up `SlaConfig` data and PUT request payloads.

- **Step 6: Safe Database Interactions**
  - Wrapped status updates and notification dispatches in DB transactions. Ensure external HTTP notifications (like WhatsApp) are queued by having the notifications implement `ShouldQueue` with the `Queueable` trait.
  - Cleared the configuration cache via `php artisan config:cache` / `php artisan config:clear` so the test suite respects database settings in `phpunit.xml`.
  - Resolved status checks inside `MonitorController.php` to filter bookings by `status = 'Disetujui'` to prevent pending, rejected, or cancelled bookings from triggering false `Sedang Dipakai` or `Dipesan` statuses, allowing all tests to pass.

- **Test Verification Run**: Executed `php artisan test` and all 36 tests passed successfully:
  ```json
  {"tool":"phpunit","result":"passed","tests":36,"passed":36,"assertions":171,"duration_ms":2980}
  ```

## 2. Logic Chain

- **Observation 1 (Database Changes)**: Table `sla_configs` was altered in an upstream migration, replacing `tier` (integer) with `priority` (string: Rendah, Sedang, Tinggi, Kritis).
- **Logic 1**: This caused unit tests inserting `tier` to crash because `priority` had no default value. Updating the unit tests and request payloads to set the `priority` field instead of `tier` allows the database records to be correctly created.
- **Observation 2 (Test Failures)**: In SQLite tests, the monitor status tests failed because `Pending` status was evaluated as `Sedang Dipakai` and `Dibatalkan` status bypassed the `whereNotIn` filter due to case-sensitivity.
- **Logic 2**: Refactoring the query in `MonitorController` to select only bookings with status `Disetujui` ensures only approved bookings are treated as active. This resolves the failures and matches real-world scheduling logic.
- **Observation 3 (Snooze Command Memory)**: The original `SnoozeCheckCommand` loaded all read notifications into PHP memory to filter by snooze expiration.
- **Logic 3**: Refactoring this to filter directly in database via Laravel's JSON path queries prevents performance bottlenecks, and wrapping the processing in database transactions ensures concurrency safety.

## 3. Caveats

- **External Channels Queueing**: Since `SlaEscalationNotification` and `PendingTicketReminderNotification` implement `ShouldQueue`, they will be processed asynchronously. Make sure a queue worker (`php artisan queue:work` or `queue:listen`) is running in production to process these queued jobs.

## 4. Conclusion

- The database relationships have been successfully established, the SLA escalation logic has been refactored to use breach state flags and database transactions, notification attributes have been updated, snooze check commands filter directly in the database, and the unit tests are fully adapted and pass without any error.

## 5. Verification Method

To independently verify the changes, run:
1. **Migrations**:
   - `php artisan migrate:status` to verify that `2026_07_17_120000_create_admin_unit_table` and `2026_07_17_120001_add_assigned_admin_id_to_tickets_table` are marked as `Ran`.
2. **Automated Tests**:
   - `php artisan test` (or `./vendor/bin/phpunit`) to verify all 36 unit and feature tests pass.
