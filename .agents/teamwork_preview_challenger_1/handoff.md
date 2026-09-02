# Handoff Report - CSAT Verification

## 1. Observation
1. **Existing CsatTest Execution**: The baseline test command `php artisan test --filter CsatTest` completed successfully with 5 tests and 31 assertions:
   ```json
   {"tool":"phpunit","result":"passed","tests":5,"passed":5,"assertions":31,"duration_ms":776}
   ```
2. **Added Adversarial Tests**: Added `test_status_case_insensitivity` and `test_csat_reminder_command_casing_issue` to `tests/Feature/CsatTest.php`.
3. **Status Case-Insensitivity Test Failure**: Running `php artisan test` after adding these cases resulted in a test failure in `test_status_case_insensitivity`:
   ```
   Tests\Feature\CsatTest::test_status_case_insensitivity
   Failed asserting that two strings are equal.
   --- Expected
   +++ Actual
   @@ @@
   -'Selesai'
   +'SELESAI'
   ```
4. **Missing Table Exception**: Running the `reminder:csat` command in `test_csat_reminder_command_casing_issue` initially failed with:
   ```
   SQLSTATE[HY000]: General error: 1 no such table: notifications (Connection: sqlite, Database: :memory:, SQL: select count(*) as "aggregate" from "notifications" where "type" = App\Notifications\CsatReminderNotification and "notifiable_type" = App\Models\User and "notifiable_id" = 1 and json_extract("data", '$.\"ticket_id\"') = 2)
   ```
5. **Casing check code in `CsatController.php`**:
   - Lines 18-22:
     ```php
     if (!in_array(strtolower($ticket->status), ['solve', 'selesai'])) {
         return back()->withErrors([
             'rating' => 'Rating hanya bisa diberikan untuk tiket yang sudah diselesaikan.',
         ]);
     }
     ```
   - Lines 42-44:
     ```php
     if (strtolower($ticket->status) === 'solve') {
         $ticket->update(['status' => 'Selesai']);
     }
     ```
6. **Case-Sensitive DB Queries**:
   - `app/Console/Commands/CsatReminderCommand.php` Line 26:
     ```php
     $tickets = Ticket::where('status', 'Solve')
     ```
   - `app/Console/Commands/PendingTicketReminderCommand.php` Line 27:
     ```php
     $tickets = Ticket::where('status', 'Pending')
     ```
   - `app/Http/Controllers/Admin/DashboardController.php` Lines 139-140:
     ```php
     DB::raw("SUM(CASE WHEN status = 'Selesai' THEN 1 ELSE 0 END) as selesai"),
     DB::raw("SUM(CASE WHEN status NOT IN ('Selesai', 'Solve') THEN 1 ELSE 0 END) as aktif"),
     ```
   - `app/Http/Controllers/Admin/UserManagementController.php` Line 108:
     ```php
     $activeTickets = $user->tickets()->whereNotIn('status', ['Selesai'])->count();
     ```
7. **Database Migrations list**: Looked up `database/migrations` and found no file matching `*notification*` or creating the `notifications` table schema.

---

## 2. Logic Chain
1. **Validation Enforcement**: The controller properly validates rating values (`required|integer|min:1|max:5`) and comments (`nullable|string|max:1000`). Bounding inputs like `0`, `6`, `-1`, `null`, `'five'`, `3.5`, and `1001` character comments are successfully blocked by validator rules.
2. **Ticket Status Casing Standardization Defect**:
   - When a ticket status is in a variation of `'selesai'` (like `'SELESAI'` or `'seLESAi'`), the controller allows the rating to be submitted because of the `strtolower` check.
   - However, the controller only updates the ticket status to `'Selesai'` if the lowercase status is `'solve'`.
   - Therefore, the ticket's status is *never standardized* to `'Selesai'` (Title Case) if it was already in a non-standard version of `'selesai'` (such as `'SELESAI'`).
3. **Downstream Query Impacts**:
   - Because the status remains `'SELESAI'`, case-sensitive SQL queries in `DashboardController.php` (e.g. `status = 'Selesai'`) and `UserManagementController.php` (e.g. `whereNotIn('status', ['Selesai'])`) fail to recognize this ticket as completed.
   - This leads to corrupted statistics and active ticket counts.
4. **Reminder Command Case Sensitivity Defect**:
   - The command `CsatReminderCommand` queries tickets with exactly `'Solve'`.
   - Under case-sensitive database environments (PostgreSQL, SQLite), this query ignores tickets with `'solve'` status (which is the lowercase casing updated by `TicketController` when resolving tickets).
   - The exact same issue applies to `PendingTicketReminderCommand` which queries `'Pending'` while other parts of the codebase update to `'pending'`.
5. **Database Notification Migration Gap**:
   - The console commands `reminder:csat` and `reminder:pending` query the `notifications` table via `\Illuminate\Notifications\DatabaseNotification`.
   - Since there is no database migration creating the `notifications` table, running either command results in a database exception (`SQLSTATE: no such table`).

---

## 3. Caveats
- Database collation behavior on MySQL: On MySQL, default collations (e.g., `utf8mb4_unicode_ci`) are case-insensitive, meaning string comparisons are case-insensitive by default. However, PostgreSQL and SQLite are case-sensitive by default, which means these bugs will manifest on any deployments using PostgreSQL or SQLite (including tests).
- Did not test WhatsApp/Email delivery failures as these are mocked/faked in the test environment.

---

## 4. Conclusion
1. **Casing Issues**: There are multiple critical gaps in status casing standardization and query case-sensitivity. The ticket statuses are not consistently handled, leading to failures in dashboard counting and CSAT reminders.
2. **Command Executability**: The reminder console commands are currently broken out-of-the-box due to a missing migration for the `notifications` table.

---

## 5. Verification Method
1. **Run CSAT test suite**:
   ```powershell
   php artisan test --filter CsatTest
   ```
   - **Pass condition**: The test suite should pass (if the bugs are fixed). Currently, it fails on `test_status_case_insensitivity` due to the lack of status standardization.
2. **Review CsatTest code**: Verify the newly added methods in `tests/Feature/CsatTest.php`:
   - `test_rating_validation_rules_are_enforced`: Validates edge rating inputs.
   - `test_status_case_insensitivity`: Asserts ticket status standardization.
   - `test_database_enforces_unique_csat_per_ticket`: Validates DB constraint.
   - `test_csat_reminder_command_casing_issue`: Validates casing behavior in reminder command.
