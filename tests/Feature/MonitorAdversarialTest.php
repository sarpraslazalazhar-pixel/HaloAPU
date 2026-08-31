<?php

namespace Tests\Feature;

use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MonitorAdversarialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Unit $unit;
    private SubUnit $subUnit;
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->unit = Unit::create([
            'nama_unit' => 'IT Support',
            'deskripsi' => 'Unit IT Support',
            'aktif' => true,
        ]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Jaringan dan Internet',
            'deskripsi' => 'Jaringan dan Internet',
            'aktif' => true,
        ]);

        $this->ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => [],
            'status' => 'open',
        ]);
    }

    /**
     * 1. Booking starting in the future on a different day should show as 'Tersedia' today.
     */
    public function test_booking_on_different_day_in_future_shows_as_tersedia_today(): void
    {
        $now = Carbon::create(2026, 7, 13, 12, 0, 0);
        Carbon::setTestNow($now);

        // Booking starts tomorrow
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang VIP',
            'tanggal_mulai' => $now->copy()->addDay()->setHour(9),
            'tanggal_selesai' => $now->copy()->addDay()->setHour(11),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertStatus(200);

        $assets = $response->original->getData()['page']['props']['assets'];
        $vipRoom = collect($assets)->firstWhere('nama_aset', 'Ruang VIP');

        $this->assertNotNull($vipRoom);
        $this->assertEquals('Tersedia', $vipRoom['status']);
        $this->assertNull($vipRoom['user']);

        Carbon::setTestNow();
    }

    /**
     * 2. Priority: 'Sedang Dipakai' takes precedence over 'Selesai Digunakan' and 'Dipesan'.
     */
    public function test_sedang_dipakai_takes_precedence_over_dipesan_for_same_asset(): void
    {
        $now = Carbon::create(2026, 7, 13, 12, 0, 0);
        Carbon::setTestNow($now);

        // Booking 1: Sedang Dipakai (active now)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang VIP',
            'tanggal_mulai' => $now->copy()->subHour(), // 11:00
            'tanggal_selesai' => $now->copy()->addHour(), // 13:00
            'status' => 'on_proses',
        ]);

        // Booking 2: Dipesan (starts in 30 mins)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang VIP',
            'tanggal_mulai' => $now->copy()->addMinutes(30),
            'tanggal_selesai' => $now->copy()->addHours(2),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertStatus(200);

        $assets = $response->original->getData()['page']['props']['assets'];
        $vipRoom = collect($assets)->firstWhere('nama_aset', 'Ruang VIP');

        $this->assertNotNull($vipRoom);
        // Should be 'Sedang Dipakai'
        $this->assertEquals('Sedang Dipakai', $vipRoom['status']);

        Carbon::setTestNow();
    }

    /**
     * 3. Priority: New upcoming booking within 1h ('Dipesan') takes precedence over recently finished booking ('Selesai Digunakan').
     */
    public function test_upcoming_booking_takes_precedence_over_recently_finished_buffer(): void
    {
        $now = Carbon::create(2026, 7, 13, 12, 0, 0);
        Carbon::setTestNow($now);

        // Booking 1: Finished 5 minutes ago (11:55)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang VIP',
            'tanggal_mulai' => $now->copy()->subHours(2),
            'tanggal_selesai' => $now->copy()->subMinutes(5),
            'status' => 'on_proses',
        ]);

        // Booking 2: Starts in 20 minutes (12:20)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang VIP',
            'tanggal_mulai' => $now->copy()->addMinutes(20),
            'tanggal_selesai' => $now->copy()->addHours(2),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        $vipRoom = collect($assets)->firstWhere('nama_aset', 'Ruang VIP');

        $this->assertNotNull($vipRoom);
        // Should show 'Dipesan' for the upcoming booking
        $this->assertEquals('Dipesan', $vipRoom['status']);

        Carbon::setTestNow();
    }

    /**
     * 4. Cancelled or rejected bookings should remain 'Tersedia'.
     */
    public function test_cancelled_or_rejected_bookings_remain_tersedia(): void
    {
        $now = Carbon::create(2026, 7, 13, 12, 0, 0);
        Carbon::setTestNow($now);

        // Rejected booking (active now)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Ditolak',
            'tanggal_mulai' => $now->copy()->subHour(),
            'tanggal_selesai' => $now->copy()->addHour(),
            'status' => 'reject',
        ]);

        // Cancelled booking (active now)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Dibatalkan',
            'tanggal_mulai' => $now->copy()->subHour(),
            'tanggal_selesai' => $now->copy()->addHour(),
            'status' => 'dibatalkan',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertStatus(200);

        $assets = $response->original->getData()['page']['props']['assets'];

        $rejectedRoom = collect($assets)->firstWhere('nama_aset', 'Ruang Ditolak');
        $cancelledRoom = collect($assets)->firstWhere('nama_aset', 'Ruang Dibatalkan');

        $this->assertNotNull($rejectedRoom);
        $this->assertEquals('Tersedia', $rejectedRoom['status']);

        $this->assertNotNull($cancelledRoom);
        $this->assertEquals('Tersedia', $cancelledRoom['status']);

        Carbon::setTestNow();
    }

    /**
     * 5. Time boundaries: exactly on start time.
     */
    public function test_time_boundary_exactly_on_start_time(): void
    {
        $now = Carbon::create(2026, 7, 13, 12, 0, 0);
        Carbon::setTestNow($now);

        // Booking starts exactly at 12:00:00
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang VIP',
            'tanggal_mulai' => $now, // 12:00
            'tanggal_selesai' => $now->copy()->addHour(), // 13:00
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertStatus(200);

        $assets = $response->original->getData()['page']['props']['assets'];
        $vipRoom = collect($assets)->firstWhere('nama_aset', 'Ruang VIP');

        $this->assertNotNull($vipRoom);
        $this->assertEquals('Sedang Dipakai', $vipRoom['status']);

        Carbon::setTestNow();
    }

    /**
     * 6. Time boundaries: exactly on end time and beyond 1 hour buffer.
     */
    public function test_time_boundary_exactly_on_end_time(): void
    {
        $now = Carbon::create(2026, 7, 13, 12, 0, 0);
        Carbon::setTestNow($now);

        // Booking ends exactly at 12:00:00 (now) -> should be 'Selesai Digunakan' (within 1 hour post buffer)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang VIP',
            'tanggal_mulai' => $now->copy()->subHour(), // 11:00
            'tanggal_selesai' => $now, // 12:00
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertStatus(200);

        $assets = $response->original->getData()['page']['props']['assets'];
        $vipRoom = collect($assets)->firstWhere('nama_aset', 'Ruang VIP');

        $this->assertNotNull($vipRoom);
        $this->assertEquals('Selesai Digunakan', $vipRoom['status']);

        Carbon::setTestNow();
    }
}
