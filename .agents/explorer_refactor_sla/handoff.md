# Handoff Report: SLA & Reminder System Analysis

## 1. Observation
Below are the exact observations made during the read-only inspection of the Halo APU V2 codebase:

- **CheckSlaCommand.php (lines 34-43)**:
  ```php
  $previousTier = $sla->current_tier;
  $currentTier = $slaCalculator->checkAndUpdateTier($sla);

  if ($currentTier > $previousTier) {
      Log::info("SLA escalation: Tiket #{$ticket->id} naik ke Tier {$currentTier}");

      $admins = Admin::all();
      foreach ($admins as $admin) {
          $admin->notify(new SlaEscalationNotification($ticket, $sla, $currentTier));
      }
      ...
  ```
- **SlaCalculator.php (lines 140-176)**:
  The `checkAndUpdateTier` method updates `is_response_breached` and `is_resolution_breached` in the database, but returns `$sla->current_tier ?? 0` without updating the `current_tier` attribute on the model or database:
  ```php
  // Return current tier for backward compatibility in calling functions, though it's no longer used for escalation.
  return $sla->current_tier ?? 0;
  ```
- **BookingReminderCommand.php (line 53)** and **PendingTicketReminderCommand.php (line 51)**:
  Both query admins mapped to units using:
  ```php
  Admin::whereHas('units', fn ($q) => $q->where('unit_id', $unitId))->get();
  ```
  However, `app/Models/Admin.php` defines no `units` relationship, and there is no pivot table mapping admins to units in `database/migrations/`.
- **PendingTicketReminderCommand.php (lines 27-29)**:
  Queries tickets preloading `assignedAdmin`:
  ```php
  $tickets = Ticket::whereRaw('LOWER(status) = ?', ['pending'])
      ->where('updated_at', '<', $cutoff)
      ->with(['subUnit.unit', 'assignedAdmin'])
      ...
  ```
  However, `app/Models/Ticket.php` has no `assignedAdmin` relationship, and `database/migrations/2026_07_10_000009_create_tickets_table.php` contains no `assigned_admin_id` column.
- **SnoozeCheckCommand.php (lines 16-25)**:
  Fetches all read notifications:
  ```php
  $notifications = DatabaseNotification::whereNotNull('read_at')
      ->get()
      ->filter(function ($notification) {
          ...
      });
  ```
  It only sets `read_at = null` and clears the data flags but does **not** re-dispatch notifications to delivery channels.
- **SlaEscalationNotification.php (lines 43-44)** and **PendingTicketReminderNotification.php (lines 49-50)**:
  ```php
  'unit' => $this->ticket->subUnit?->unit?->nama,
  'sub_unit' => $this->ticket->subUnit?->nama,
  ```
  The database columns are actually `nama_unit` on the `units` table and `nama_layanan` on the `sub_units` table.
- **SlaCalculatorTest.php (lines 169-186)**:
  Seeds SLA configs using the dropped `tier` column:
  ```php
  SlaConfig::create([
      'sub_unit_id' => $this->subUnit->id,
      'tier' => 1,
      'jenis' => 'respon',
      'threshold_minutes' => 30,
  ]);
  ```

---

## 2. Logic Chain
1. **SLA Escalation Notification failure**:
   - Because `checkAndUpdateTier()` returns `current_tier` unchanged and does not update it in the DB (Observation 2), the condition `if ($currentTier > $previousTier)` (Observation 1) will always resolve to `false`.
   - Therefore, the block that triggers `SlaEscalationNotification` is never executed.
2. **Runtime Crashes in commands**:
   - Running `reminder:booking` or `reminder:pending` triggers `Admin::whereHas('units', ...)` (Observation 3). Since `units` relation is undefined in `Admin.php`, Eloquent throws a `RelationNotFoundException`.
   - Preloading `assignedAdmin` in `reminder:pending` query (Observation 4) triggers a `RelationNotFoundException` because the relation is undefined on `Ticket.php`.
   - Both commands crash at runtime immediately.
3. **Blank values in notifications**:
   - Accessing `nama` attribute (Observation 6) on `Unit` and `SubUnit` returns `null` because the columns are named `nama_unit` and `nama_layanan`.
   - Consequently, the unit and sub-unit names appear as blank in all dispatched notification templates.
4. **Memory overhead in Snooze Check**:
   - Reading all read notifications with `DatabaseNotification::whereNotNull('read_at')->get()` (Observation 5) instantiates all read notification records in memory. As the table grows, memory usage increases linearly, leading to memory exhaustion.
   - The command clears the database flags but never notifies channels again, leaving snoozed notifications silently failing to alert users externally.
5. **Broken automated tests**:
   - Test seeders (Observation 7) try to save `tier` to `sla_configs` which no longer exists as a column. This throws a database exception, failing the test suite.

---

## 3. Caveats
- Checked and verified that `SystemConfig::getValue` and `SystemConfig::setValue` are correctly implemented.
- We assume that `WhatsAppChannel` works as long as the parameters (`wa_api_key`, `wa_number_key`) are correctly supplied in the system configurations.
- We assume that tickets are intended to have an assigned admin, which requires a new migration.

---

## 4. Conclusion
The SLA checker and Reminder systems contain several major structural bugs and gaps that will cause immediate command crashes, silent notification failures, and test suite failures.
To address these, the Implementer must:
1. Create migrations to link `admins` to `units` (pivot table `admin_unit`) and add `assigned_admin_id` to `tickets`.
2. Refactor `CheckSlaCommand` to detect breaches based on state transitions of the `is_response_breached` and `is_resolution_breached` fields.
3. Refactor `SnoozeCheckCommand` to query snoozes on database level and re-dispatch notification classes.
4. Update notification templates to use `nama_unit` and `nama_layanan` attributes.
5. Update unit tests to target `priority` instead of `tier` configurations.

---

## 5. Verification Method
1. Run the existing tests using `php artisan test` or `./vendor/bin/phpunit`. They will fail initially due to the missing columns/mismatch fields.
2. After the implementer applies the refactoring, run `php artisan test` again to verify that SLA and config updates pass.
3. Run `php artisan reminder:booking`, `php artisan reminder:pending`, `php artisan reminder:csat`, and `php artisan reminder:snooze-check` to verify that they run without throwing exceptions.
