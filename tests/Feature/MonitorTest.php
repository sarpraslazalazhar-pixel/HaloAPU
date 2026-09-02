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

class MonitorTest extends TestCase
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
     * Logic for status determination (Tersedia, Dipesan, Sedang Dipakai, Selesai Digunakan, Menunggu Persetujuan) works correctly.
     */
    public function test_asset_status_determination_works_correctly(): void
    {
        // 1. Initially, with no bookings, check assets are empty
        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('User/Monitor/Index')
            ->has('assets', 0)
        );

        // Set a base fixed time for testing
        $now = Carbon::create(2026, 7, 13, 12, 0, 0);
        Carbon::setTestNow($now);

        // 2. Create an asset with a booking that ended 3 hours ago (> 1 hour buffer). It should show as 'Tersedia'
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Meeting A',
            'tanggal_mulai' => $now->copy()->subHours(5),
            'tanggal_selesai' => $now->copy()->subHours(3),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('User/Monitor/Index')
            ->has('assets', 1, fn (Assert $asset) => $asset
                ->where('nama_aset', 'Ruang Meeting A')
                ->where('tipe', 'ruang')
                ->where('status', 'Tersedia')
                ->where('user', null)
                ->etc()
            )
        );

        // 3. Create a booking that ended 30 minutes ago (within 1 hour post-buffer). It should show as 'Selesai Digunakan'
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Meeting Buffer',
            'tanggal_mulai' => $now->copy()->subHours(2),
            'tanggal_selesai' => $now->copy()->subMinutes(30),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        $meetingBuffer = collect($assets)->firstWhere('nama_aset', 'Ruang Meeting Buffer');
        $this->assertEquals('Selesai Digunakan', $meetingBuffer['status']);
        $this->assertEquals($this->user->name ?? $this->user->username, $meetingBuffer['user']);

        // 4. Create a booking for 'Sedang Dipakai' (start_time <= now <= end_time, on_proses)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Meeting B',
            'tanggal_mulai' => $now->copy()->subHour(),
            'tanggal_selesai' => $now->copy()->addHour(),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        $meetingB = collect($assets)->firstWhere('nama_aset', 'Ruang Meeting B');
        $this->assertEquals('Sedang Dipakai', $meetingB['status']);
        $this->assertEquals($this->user->name ?? $this->user->username, $meetingB['user']);

        // 5. Create a booking for 'Dipesan' starting in 30 minutes (within 1 hour pre-buffer)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'kendaraan',
            'nama_aset' => 'Avanza',
            'tanggal_mulai' => $now->copy()->addMinutes(30),
            'tanggal_selesai' => $now->copy()->addHours(2),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        $avanza = collect($assets)->firstWhere('nama_aset', 'Avanza');
        $this->assertEquals('Dipesan', $avanza['status']);
        $this->assertEquals($this->user->name ?? $this->user->username, $avanza['user']);

        // 6. Booking starting in 3 hours (> 1 hour pre-buffer) should show 'Tersedia' on current card
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'kendaraan',
            'nama_aset' => 'Innova',
            'tanggal_mulai' => $now->copy()->addHours(3),
            'tanggal_selesai' => $now->copy()->addHours(5),
            'status' => 'on_proses',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        $innova = collect($assets)->firstWhere('nama_aset', 'Innova');
        $this->assertEquals('Tersedia', $innova['status']);
        $this->assertNull($innova['user']);

        // 7. Booking starting 1 minute ago with status 'open' (submitted at 12:01 for 12:00) -> should show 'Menunggu Persetujuan'
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Urgent',
            'tanggal_mulai' => $now->copy()->subMinute(),
            'tanggal_selesai' => $now->copy()->addHour(),
            'status' => 'open',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        $urgent = collect($assets)->firstWhere('nama_aset', 'Ruang Urgent');
        $this->assertNotNull($urgent);
        $this->assertEquals('Menunggu Persetujuan', $urgent['status']);

        // Reset testing time
        Carbon::setTestNow();
    }
}
