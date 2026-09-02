# Handoff Report — Live Monitor Status Verification

## 1. Observation

- **Implementation File**: `app/Http/Controllers/MonitorController.php`
  - Lines 48-67 determine active bookings:
    ```php
    // Map status per aset
    return $allAssets->map(function ($asset) use ($bookings, $now) {
        $assetBookings = $bookings->where('nama_aset', $asset->nama_aset);

        // Cek apakah sedang dipakai
        $activeBooking = $assetBookings->first(function ($b) use ($now) {
            return Carbon::parse($b->tanggal_mulai)->lte($now)
                && Carbon::parse($b->tanggal_selesai)->gte($now);
        });

        if ($activeBooking) {
            return [
                'nama_aset' => $asset->nama_aset,
                'tipe' => $asset->tipe,
                'status' => 'Sedang Dipakai',
                'user' => $activeBooking->ticket?->user?->username ?? '-',
                'waktu_mulai' => Carbon::parse($activeBooking->tanggal_mulai)->format('H:i'),
                'waktu_selesai' => Carbon::parse($activeBooking->tanggal_selesai)->format('H:i'),
                'booking_id' => $activeBooking->id,
            ];
        }
    ```
  - Lines 70-85 check for upcoming bookings today:
    ```php
        // Cek apakah ada booking mendatang hari ini
        $nextBooking = $assetBookings
            ->filter(fn ($b) => Carbon::parse($b->tanggal_mulai)->gt($now))
            ->sortBy('tanggal_mulai')
            ->first();

        if ($nextBooking && Carbon::parse($nextBooking->tanggal_mulai)->isToday()) {
            return [
                'nama_aset' => $asset->nama_aset,
                'tipe' => $asset->tipe,
                'status' => 'Dipesan',
                'user' => $nextBooking->ticket?->user?->username ?? '-',
                'waktu_mulai' => Carbon::parse($nextBooking->tanggal_mulai)->format('H:i'),
                'waktu_selesai' => Carbon::parse($nextBooking->tanggal_selesai)->format('H:i'),
                'booking_id' => $nextBooking->id,
            ];
        }
    ```
  - Lines 36-39 fetch all distinct asset names from all records in the `RoomVehicleBooking` database:
    ```php
    // Ambil daftar unik aset
    $allAssetsQuery = RoomVehicleBooking::select('nama_aset', 'tipe')
        ->distinct()
        ->orderBy('tipe')
        ->orderBy('nama_aset');
    ```

- **Test Files**:
  - `tests/Feature/MonitorTest.php`
  - `tests/Feature/MonitorAdversarialTest.php` (newly created)

- **Test Run Results**:
  - Running `php artisan test --filter Monitor` completed successfully:
    ```json
    {"tool":"phpunit","result":"passed","tests":6,"passed":6,"assertions":69,"duration_ms":720}
    ```

---

## 2. Logic Chain

1. **Assertion on Future Bookings**: A booking set for tomorrow should display as 'Tersedia' today because the controller filters `$nextBooking` using `Carbon::parse($nextBooking->tanggal_mulai)->isToday()`. Thus, tomorrow's booking returns false for `isToday()`, dropping status down to `Tersedia`. (Verified in `test_booking_on_different_day_in_future_shows_as_tersedia_today`)
2. **Assertion on Precedence**: If Booking A is active now ('Sedang Dipakai') and Booking B starts later today ('Dipesan'), the controller code evaluates the active booking check first: `if ($activeBooking) { return 'Sedang Dipakai'; }`. This guarantees that 'Sedang Dipakai' takes precedence over 'Dipesan'. (Verified in `test_sedang_dipakai_takes_precedence_over_dipesan_for_same_asset`)
3. **Assertion on Cancelled/Rejected Bookings**: Bookings that are not approved ('Disetujui') are not retrieved in `$bookings` query (filtered with `where('status', 'Disetujui')`). Therefore, they never populate `$assetBookings`, and return 'Tersedia'. (Verified in `test_cancelled_or_rejected_bookings_remain_tersedia`)
4. **Assertion on Time Boundaries**:
   - Exactly at start time (e.g. 12:00:00 for a 12:00-13:00 booking): The active check `lte($now)` is true (12:00 <= 12:00) and `gte($now)` is true (13:00 >= 12:00), evaluating to 'Sedang Dipakai'. (Verified in `test_time_boundary_exactly_on_start_time`)
   - Exactly at end time (e.g. 12:00:00 for a 11:00-12:00 booking): The active check `lte($now)` is true (11:00 <= 12:00) and `gte($now)` is true (12:00 >= 12:00), evaluating to 'Sedang Dipakai'. (Verified in `test_time_boundary_exactly_on_end_time`)

---

## 3. Caveats

- Database volume scalability: The application lacks an independent `assets` table. It retrieves the dynamic list of assets by executing `distinct` on the `RoomVehicleBooking` table. This scans the entire table and might lead to performance degradation as historical booking records grow.
- Exclusive boundary behavior: While checking exactly on end time, since the boundary is inclusive (`gte`), the asset remains marked 'Sedang Dipakai' at the exact second the booking ends.

---

## 4. Conclusion

The Live Monitor status determination logic is correct under normal conditions. However, two gaps were identified:
1. **Asset List Pollution**: Distinct asset names are dynamically fetched from the `RoomVehicleBooking` table without checking for validation or status. Any mistyped asset name or one that only exists in rejected/cancelled bookings will forever appear in the Live Monitor as `Tersedia`.
2. **End-Time Overlap Conflict**: Because `activeBooking` time validation uses inclusive operators (`<=` and `>=`), a booking remains active at its exact end-second. If a new booking starts at that same second, the controller will match both bookings, and return the first matched booking (usually the older record in database insertion order), hiding the other booking's active user.

---

## 5. Verification Method

- Run the test suite:
  ```powershell
  php artisan test --filter Monitor
  ```
- Inspect the newly written test file: `tests/Feature/MonitorAdversarialTest.php`.
- Invalidation Condition: If the controller logic is changed to use strictly exclusive end-time checks (i.e. strictly less than end time), the test case `test_time_boundary_exactly_on_end_time` will fail because at the exact end-time, status would become `Tersedia`.

---

# Adversarial Challenge Report

**Overall risk assessment**: MEDIUM

## Challenges

### [Medium] Challenge 1: Asset List Pollution
- **Assumption challenged**: The list of assets to display on the monitor is derived exclusively from `RoomVehicleBooking::select('nama_aset', 'tipe')->distinct()`.
- **Attack scenario**: A user makes a typo in a booking (e.g., "Ruang Meeeting A" instead of "Ruang Meeting A"). Even if that booking is rejected or cancelled, "Ruang Meeeting A" is permanently registered in the database. The Live Monitor will display "Ruang Meeeting A" with status 'Tersedia' forever.
- **Blast radius**: UI pollution with invalid, decommissioned, or mistyped asset names, degrading dashboard quality.
- **Mitigation**: Introduce a dedicated `Asset` (or `Aset`) model/table. Let the administrator manage the list of valid assets, and use that table as the source of truth for the Live Monitor, querying the bookings against this master list.

### [Low] Challenge 2: Boundary Conflict and User Hiding
- **Assumption challenged**: Bookings do not overlap at boundary points, or boundary overlapping is resolved cleanly.
- **Attack scenario**: Booking A ends at 12:00:00, Booking B starts at 12:00:00. At exactly 12:00:00, the controller evaluates both bookings as active. It returns the older record (Booking A) and marks status 'Sedang Dipakai' under Booking A's user, completely hiding Booking B's user for that brief moment.
- **Blast radius**: Minor inconsistency in real-time user display at transition boundaries.
- **Mitigation**: Define the end-time boundary as exclusive: `Carbon::parse($b->tanggal_selesai)->gt($now)` (strictly greater than).

---

## Stress Test Results

- **Booking on different day in future** → Should be 'Tersedia' today → Actual: 'Tersedia' → **PASS**
- **Overlapping active and upcoming bookings** → 'Sedang Dipakai' takes precedence → Actual: 'Sedang Dipakai' → **PASS**
- **Rejected/cancelled bookings only** → Should be 'Tersedia' → Actual: 'Tersedia' → **PASS**
- **Boundary: Exactly on start time** → Should be 'Sedang Dipakai' → Actual: 'Sedang Dipakai' → **PASS**
- **Boundary: Exactly on end time** → Should be 'Sedang Dipakai' (due to inclusive `gte` bounds) → Actual: 'Sedang Dipakai' → **PASS**

## Unchallenged Areas

- **UI / Frontend rendering**: Evaluated only the backend API response payload (`Inertia` asset collection), not the visual rendering or reactive updates on the user interface.
