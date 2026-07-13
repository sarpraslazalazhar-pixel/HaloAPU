## 2026-07-13T03:20:06Z
You are teamwork_preview_worker.
Your role: Full Stack Developer.
Your working directory is 'c:\Users\LAZ AL AZHAR\Documents\Halo APU V2\.agents\teamwork_preview_worker_implementation'.

Objective:
Implement the CSAT and Live Monitor requirements based on 'Doc/PLAN-FASE-5.md' and the global plan in 'PROJECT.md'.

Detailed Tasks:
1. Migration & Database Setup:
   - Create a Laravel migration file for 'room_vehicle_bookings' table at 'database/migrations/2026_07_13_000004_create_room_vehicle_bookings_table.php'. Columns:
     - id (big increments)
     - ticket_id (foreign key referencing tickets(id) with cascade delete)
     - tipe (string, representing 'ruang' or 'kendaraan')
     - nama_aset (string)
     - tanggal_mulai (datetime)
     - tanggal_selesai (datetime)
     - status (string, default 'Disetujui')
     - timestamps
   - Run the migration using 'php artisan migrate'.

2. Backend Models & Controllers:
   - Create model 'app/Models/RoomVehicleBooking.php'. Cast 'tanggal_mulai' and 'tanggal_selesai' to datetime, and define 'ticket()' belongsTo relationship.
   - Update 'app/Models/Ticket.php' to add 'booking()' hasOne relation to RoomVehicleBooking.
   - Fix 'app/Http/Controllers/User/TicketHistoryController.php' show method to load the 'csat' relation.
   - Implement 'app/Http/Controllers/MonitorController.php' containing:
     - `getAssetData(?string $tipe = null)` logic as specified in Doc/PLAN-FASE-5.md to correctly determine status:
       - 'Sedang Dipakai': if there is an approved booking where start_time <= now <= end_time.
       - 'Dipesan': if there is an approved booking where start_time > now but starts today.
       - 'Tersedia': otherwise.
     - `userIndex()` and `adminIndex()` returning Inertia rendering of 'User/Monitor/Index' and 'Admin/Monitor/Index'.

3. Web Routes:
   - Register the Monitor routes in 'routes/web.php':
     - User side: GET '/monitor' mapped to MonitorController@userIndex (inside the 'auth' middleware group).
     - Admin side: GET '/admin/monitor' mapped to MonitorController@adminIndex (inside the 'auth:admin' middleware group).

4. Frontend Integration:
   - Fix 'resources/js/Pages/User/Tiket/Detail.tsx' to pass `existingRating={ticket.csat?.rating}` instead of hardcoded `null`.
   - Create 'resources/js/Components/MonitorGrid.tsx' as specified in Doc/PLAN-FASE-5.md, using the `usePoll(10000)` hook from `@inertiajs/react` for auto-refresh.
   - Create 'resources/js/Pages/User/Monitor/Index.tsx' and 'resources/js/Pages/Admin/Monitor/Index.tsx' using the 'MonitorGrid' component.

5. Automated Testing:
   - Create a PHPUnit feature test 'tests/Feature/CsatTest.php' validating:
     - User can submit rating successfully for their own ticket in 'Solve' or 'Selesai' status.
     - User cannot submit rating for non-owned tickets or tickets with other statuses.
     - User cannot submit rating twice for the same ticket.
     - Validation rules are enforced (rating 1-5, komentar max 1000).
   - Create a PHPUnit feature test 'tests/Feature/MonitorTest.php' validating:
     - Logic for status determination (Tersedia, Dipesan, Sedang Dipakai) works correctly.
   - Run tests using 'php artisan test' and ensure they pass.

6. Build Verification:
   - Build frontend assets using 'npm run build' and verify it completes without errors.

MANDATORY INTEGRITY WARNING:
DO NOT CHEAT. All implementations must be genuine. DO NOT hardcode test results, create dummy/facade implementations, or circumvent the intended task. A Forensic Auditor will independently verify your work. Integrity violations WILL be detected and your work WILL be rejected.

Delivery:
Write your handoff report to 'c:\Users\LAZ AL AZHAR\Documents\Halo APU V2\.agents\teamwork_preview_worker_implementation\handoff.md' summarizing what you did, the commands run, test outcomes, and code layout compliance.
