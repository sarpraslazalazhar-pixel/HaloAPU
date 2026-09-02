# Detailed Analysis: SLA Checker and Reminder Systems in Halo APU

This report details the findings from the investigation of the SLA checker and Reminder systems in the Halo APU V2 Laravel Helpdesk application. Several critical runtime bugs, logical flaws, architectural gaps, and performance bottlenecks were discovered.

---

## 1. SLA Checker Investigation

### Core Files Examined:
- `app/Console/Commands/CheckSlaCommand.php`
- `app/Services/SlaCalculator.php`

### Functionality Mapping:
1. **Priority Handling**:
   - `SlaCalculator` correctly loads work hours configuration (defaulting to Mon-Fri 08:00 - 16:00 if no database value is present).
   - In `checkAndUpdateTier()`, the ticket priority is retrieved (`$priority = $ticket->priority ?? 'Sedang';`) and used to query the SLA threshold:
     - `SlaConfig::getThreshold($subUnitId, $priority, 'respon')`
     - `SlaConfig::getThreshold($subUnitId, $priority, 'penyelesaian')`
   - This aligns with the new priority-based model configuration (dropped `tier` column in favour of `priority`).

2. **Ticket Status Updates**:
   - Currently, neither `CheckSlaCommand` nor `SlaCalculator::checkAndUpdateTier` updates the `status` of the `Ticket` model itself when a breach occurs.
   - They only update `is_response_breached` and `is_resolution_breached` boolean flags on the `TicketSlaTracking` model.
   - If tickets should transition to a breached state, a mechanism to log the state transition or update a field is missing.

3. **Database Transactions**:
   - There is **no transaction safety** implemented in `CheckSlaCommand`. If an exception is thrown (e.g. during a notification dispatch), the command terminates immediately. This leaves the database in a partially processed state and stops processing for remaining tickets in the loop.

4. **Escalation Notification Triggering**:
   - The command attempts to detect escalation by comparing `$currentTier > $previousTier`.
   - However, `SlaCalculator::checkAndUpdateTier` only updates breach flags and returns `$sla->current_tier ?? 0` unchanged. It does **not** recalculate or update `current_tier` in the database.
   - Because of this, `$currentTier` always equals `$previousTier` (usually 0), and the notification block `if ($currentTier > $previousTier)` is **never executed**. No escalation notifications are ever sent.
   - If notifications were to send, the command attempts to notify all admins in a loop using `Admin::all()`. This is highly inefficient and creates substantial overhead.
   - Additionally, `SlaEscalationNotification` still expects a `tier` parameter in its constructor, which is outdated since `sla_configs` has moved to a priority-based structure.

---

## 2. Reminder Systems Investigation

### Core Files Examined:
- `app/Console/Commands/BookingReminderCommand.php`
- `app/Console/Commands/CsatReminderCommand.php`
- `app/Console/Commands/PendingTicketReminderCommand.php`
- `app/Console/Commands/SnoozeCheckCommand.php`

### Key Findings & Bugs:

#### A. `BookingReminderCommand.php`
- **Query**: Fetches bookings with `status` = `'Disetujui'` where `tanggal_mulai` matches `$targetDate` (`now()->addDays($leadDays)->toDateString()`). This is functionally correct.
- **Already Sent Check**: Looks up `DatabaseNotification` where the type is `BookingReminderNotification` and the JSON data matches `data->booking_id = $booking->id` sent today. This is safe, provided the database channel is utilized.
- **Critical Crash Bug**: Calls `Admin::whereHas('units', ...)` to notify admins of the booking's unit. However, the **`Admin` model has no `units` relationship defined**, and there is no pivot table mapping admins to units. Running the command immediately throws a `RelationNotFoundException` and crashes.

#### B. `CsatReminderCommand.php`
- **Query**: Correctly queries solved tickets that do not have a CSAT rating and are older than the config cutoff.
- **Notification Safety**: Implements solid anti-spam (max 3 reminders per ticket) and intervals (max 1 reminder per 2 days). Queries are done on database notifications and are safe.
- **Status Checks**: Safely handles different casing of `solve` status.
- **Status Relationship**: `csat()` relation is correctly defined in `Ticket.php`.

#### C. `PendingTicketReminderCommand.php`
- **Critical Preload Bug**: Queries tickets using `->with(['subUnit.unit', 'assignedAdmin'])`. However, **`assignedAdmin` is not a relationship defined on the `Ticket` model**, and the `tickets` table has **no `assigned_admin_id` column** in the database schema. Preloading it throws a `RelationNotFoundException` and crashes the command instantly.
- **Critical Relation Bug**: Same as the booking command, it attempts to fetch admins mapped to the unit using `Admin::whereHas('units', ...)`, which crashes because the `units` relation is undefined on the `Admin` model.

#### D. `SnoozeCheckCommand.php`
- **Severe Memory/Performance Bottleneck**: The command runs `DatabaseNotification::whereNotNull('read_at')->get()` and filters the collection in memory using PHP. In a production system, this loads **all read notifications** into memory, leading to memory exhaustion and server crashes.
- **Silent Notification Failure**: The command updates the database record by clearing snooze flags and setting `read_at = null`. However, **it does not re-dispatch the notification class or trigger the delivery channels (email, WhatsApp)**. The notification will simply appear as unread in the web dashboard, but the user will never receive the external reminders they snoozed.
- **Safe updates**: Directly uses `$notification->update([...])` which is functional but lacks transaction safety.

---

## 3. General & Model Bugs

### 1. Attribute Access Mismatch in Notifications
In `SlaEscalationNotification.php` and `PendingTicketReminderNotification.php`, the notification text and database payloads try to retrieve unit and sub-unit names using:
- `subUnit?->unit?->nama`
- `subUnit?->nama`

However, the actual database columns are:
- `nama_unit` on the `units` table.
- `nama_layanan` on the `sub_units` table.

Consequently, all unit and sub-unit names display as **empty/blank** in notifications.

### 2. Broken Automated Tests
The existing test files `tests/Unit/SlaCalculatorTest.php` and `tests/Unit/SlaCalculatorStressTest.php` are completely out of date with the new priority-based schema migration:
- They attempt to seed `sla_configs` using a non-existent `tier` column.
- They send API PUT requests to `admin.sla-config.update` with `tier` but without the now-required `priority` column.
- Running `php artisan test` will fail due to validation errors and database exceptions.

---

## 4. Worker Refactoring Strategy

We recommend a 6-step refactoring strategy for the implementer to fix all bugs and establish safe database interactions.

### Step 1: Establish Database Relationships
1. **Admin-Unit Relationship**:
   - Create a migration for a pivot table `admin_unit` (`admin_id`, `unit_id`).
   - Define a `belongsToMany` relationship named `units` on the `Admin` model.
   - Define a `belongsToMany` relationship named `admins` on the `Unit` model.
2. **Ticket Assignment**:
   - Create a migration adding a nullable column `assigned_admin_id` to the `tickets` table, foreign-keyed to `admins.id`.
   - Define a `belongsTo` relationship named `assignedAdmin` on the `Ticket` model.

### Step 2: Refactor SLA Escalation Logic
1. **Breach Flag Tracking**:
   - Instead of checking if a numeric tier has increased (which no longer exists), compare the old breach flags on the `$sla` tracking record with the recalculated flags.
   - Refactor `CheckSlaCommand::handle()` to run in database transactions per ticket, catching exceptions so one failure doesn't halt the command:
     ```php
     foreach ($activeTickets as $ticket) {
         DB::transaction(function () use ($ticket, $slaCalculator) {
             $sla = $ticket->slaTracking;
             $wasRespBreached = $sla->is_response_breached;
             $wasResBreached = $sla->is_resolution_breached;

             $slaCalculator->checkAndUpdateTier($sla);
             $sla->refresh();

             if (!$wasRespBreached && $sla->is_response_breached) {
                 // Dispatch Response Breach Notification
                 $this->dispatchBreachNotification($ticket, $sla, 'respon');
             }

             if (!$wasResBreached && $sla->is_resolution_breached) {
                 // Dispatch Resolution Breach Notification
                 $this->dispatchBreachNotification($ticket, $sla, 'penyelesaian');
             }
         });
     }
     ```
2. **Notification Refactoring**:
   - Refactor `SlaEscalationNotification` to accept the breach type (`respon` or `penyelesaian`) and ticket's priority (`Rendah`, `Sedang`, `Tinggi`, `Kritis`) instead of a numeric `tier`.
   - Map priorities to delivery channels (e.g. `Kritis` & `Tinggi` enable WhatsApp, others only database and email).

### Step 3: Fix Notification Attribute Names
Update both `SlaEscalationNotification.php` and `PendingTicketReminderNotification.php` to access:
- `$ticket->subUnit?->unit?->nama_unit` instead of `nama`
- `$ticket->subUnit?->nama_layanan` instead of `nama`

### Step 4: Refactor Snooze Check Command
1. **Database-Level Query**:
   Filter out snooze notifications directly in the database instead of in memory:
   ```php
   $notifications = DatabaseNotification::whereNotNull('read_at')
       ->where('data->snoozed', true)
       ->whereNotNull('data->snoozed_until')
       ->whereNull('data->done_at')
       ->where('data->snoozed_until', '<=', now()->toISOString())
       ->get();
   ```
2. **Re-fire Channel Delivery**:
   Re-dispatch the actual notification classes using the details saved in the database:
   ```php
   foreach ($notifications as $notification) {
       $data = $notification->data;
       $notifiable = $notification->notifiable;
       $type = $notification->type;

       $newNotification = null;

       if ($type === CsatReminderNotification::class && isset($data['ticket_id'])) {
           $ticket = Ticket::find($data['ticket_id']);
           if ($ticket) $newNotification = new CsatReminderNotification($ticket);
       } elseif ($type === BookingReminderNotification::class && isset($data['booking_id'])) {
           $booking = RoomVehicleBooking::find($data['booking_id']);
           if ($booking) $newNotification = new BookingReminderNotification($booking);
       } // Add handlers for other notification types...

       if ($newNotification && $notifiable) {
           $notifiable->notify($newNotification);
           
           // Mark old notification as done to prevent infinite loops
           $data['done_at'] = now()->toISOString();
           $notification->update(['data' => $data]);
       }
   }
   ```

### Step 5: Fix Unit Tests
1. Update `tests/Unit/SlaCalculatorTest.php` and `tests/Unit/SlaCalculatorStressTest.php` to replace the word `tier` with `priority` (e.g. `'priority' => 'Sedang'`) in all `SlaConfig` instantiations.
2. Update the batch PUT endpoint payload in tests to send the required `priority` fields.
3. Update assertions to target `priority` and confirm correct database record insertion.

### Step 6: Safe Database Interactions
- Wrap all ticket-state changes and notifications inside block transactions where possible.
- Ensure that notifications that trigger external HTTP calls (like `WhatsAppChannel`) are queued (`implements ShouldQueue`) so they do not hold database locks or cause performance bottlenecks during the console command runs.
