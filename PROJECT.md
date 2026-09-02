# Project: Halo APU V2 Phase 5 CSAT and Live Monitor Implementation

## Architecture
- **Backend**: Laravel 11/13, Eloquent ORM. Includes:
  - `RoomVehicleBooking` Model and migration representing assets booking.
  - `Csat` Model for customer satisfaction surveys (already exists in migrations/models but needs check/run).
  - Controllers: `CsatController` (user side rating submission & history), `Admin\CsatController` (admin dashboard analytics), and `MonitorController` (real-time status check for assets).
  - Routes in `routes/web.php` mapping these routes.
- **Frontend**: React 19, Inertia.js v2, TypeScript. Includes:
  - `CsatDialog` in Ticket Detail view (`User/Tiket/Detail.tsx`).
  - `User/Csat/Riwayat.tsx` page.
  - `Admin/Csat/Index.tsx` page.
  - `Components/MonitorGrid.tsx` for shared auto-polling (via `usePoll` hook) monitor layout.
  - `User/Monitor/Index.tsx` and `Admin/Monitor/Index.tsx` page wrappers.
- **Testing**: PHPUnit/Pest automated tests verifying backend validation of CSAT insert rules and Live Monitor status determination logic.

## Code Layout
- Controllers:
  - `app/Http/Controllers/CsatController.php`
  - `app/Http/Controllers/Admin/CsatController.php`
  - `app/Http/Controllers/MonitorController.php`
- Models:
  - `app/Models/Csat.php`
  - `app/Models/RoomVehicleBooking.php`
  - `app/Models/Ticket.php` (update relation)
- Migrations:
  - `database/migrations/2026_07_13_000001_create_csats_table.php` (run if not migrated)
  - `database/migrations/2026_07_13_000004_create_room_vehicle_bookings_table.php` (create and run)
- React Components/Pages:
  - `resources/js/Components/CsatDialog.tsx` or inline in Ticket Detail
  - `resources/js/Pages/User/Csat/Riwayat.tsx`
  - `resources/js/Pages/Admin/Csat/Index.tsx`
  - `resources/js/Components/MonitorGrid.tsx`
  - `resources/js/Pages/User/Monitor/Index.tsx`
  - `resources/js/Pages/Admin/Monitor/Index.tsx`
- Routes:
  - `routes/web.php`
- Tests:
  - `tests/Feature/CsatTest.php`
  - `tests/Feature/MonitorTest.php`

## Milestones
| # | Name | Scope | Dependencies | Status |
|---|---|---|---|---|
| 1 | Setup & Migration | Verify existing migrations, create `room_vehicle_bookings` migration, run migrate, and check seeder status. | none | DONE |
| 2 | Model & Controller Updates | Implement `RoomVehicleBooking` model, update `Ticket` model relations. Implement/verify `CsatController`, `Admin\CsatController`, and `MonitorController` backend logic. | M1 | DONE |
| 3 | Routing Configuration | Register CSAT and Live Monitor routes in `routes/web.php`. | M2 | DONE |
| 4 | Frontend UI Integration | Implement CSAT rating dialog on Ticket Detail page, CSAT history page for users, CSAT dashboard for admin, and Live Monitor polling grids for users/admins. | M3 | DONE |
| 5 | Automated Testing | Write PHPUnit/Pest tests validating CSAT limits (status, ownership, single submission) and Live Monitor status selection logic. | M2, M3 | DONE |
| 6 | E2E Audit & Completion | Run full test suite, verify layout compliance, run Forensic Auditor, and deliver reports. | M4, M5 | DONE |

## Interface Contracts
### CSAT API
- `POST /csat/{ticket}`:
  - Request: `{ rating: int (1-5), komentar: string (optional, max 1000) }`
  - Response: Redirect back with success message or errors.
- `GET /csat/riwayat`:
  - Returns Inertia Render of `User/Csat/Riwayat` with paginated user's rating history.
- `GET /admin/csat`:
  - Returns Inertia Render of `Admin/Csat/Index` with paginated ratings and aggregate stats (avg rating, distribution, rating per unit).

### Live Monitor API
- `GET /monitor` (User) & `GET /admin/monitor` (Admin):
  - Response: Inertia Render of monitor page with assets list.
  - Data structure returned:
    ```json
    {
      "assets": [
        {
          "nama_aset": "string",
          "tipe": "ruang|kendaraan",
          "status": "Tersedia|Dipesan|Sedang Dipakai",
          "user": "string|null",
          "waktu_mulai": "string (H:i)|null",
          "waktu_selesai": "string (H:i)|null",
          "booking_id": "int|null"
        }
      ],
      "lastUpdated": "string (H:i:s)"
    }
    ```
