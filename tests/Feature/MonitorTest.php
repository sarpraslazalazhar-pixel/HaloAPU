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
     * Logic for status determination (Tersedia, Dipesan, Sedang Dipakai) works correctly.
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

        // 2. Create an asset with a booking that ended in the past. It should show as 'Tersedia'
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Meeting A',
            'tanggal_mulai' => $now->copy()->subHours(3),
            'tanggal_selesai' => $now->copy()->subHours(1),
            'status' => 'Disetujui',
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

        // 3. Create a booking for 'Sedang Dipakai' (start_time <= now <= end_time)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Meeting B',
            'tanggal_mulai' => $now->copy()->subHour(),
            'tanggal_selesai' => $now->copy()->addHour(),
            'status' => 'Disetujui',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $response->assertInertia(fn (Assert $page) => $page
            ->component('User/Monitor/Index')
            ->has('assets', 2)
        );

        $assets = $response->original->getData()['page']['props']['assets'];
        $meetingB = collect($assets)->firstWhere('nama_aset', 'Ruang Meeting B');
        $this->assertEquals('Sedang Dipakai', $meetingB['status']);
        $this->assertEquals($this->user->username, $meetingB['user']);

        // 4. Create a booking for 'Dipesan' (start_time > now but starts today)
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'kendaraan',
            'nama_aset' => 'Avanza',
            'tanggal_mulai' => $now->copy()->addHours(2),
            'tanggal_selesai' => $now->copy()->addHours(4),
            'status' => 'Disetujui',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        
        $avanza = collect($assets)->firstWhere('nama_aset', 'Avanza');
        $this->assertEquals('Dipesan', $avanza['status']);
        $this->assertEquals($this->user->username, $avanza['user']);

        // 5. If booking starts tomorrow, it should not be 'Dipesan' today but 'Tersedia'
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'kendaraan',
            'nama_aset' => 'Innova',
            'tanggal_mulai' => $now->copy()->addDay()->setHour(10),
            'tanggal_selesai' => $now->copy()->addDay()->setHour(12),
            'status' => 'Disetujui',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        
        $innova = collect($assets)->firstWhere('nama_aset', 'Innova');
        $this->assertEquals('Tersedia', $innova['status']);
        $this->assertNull($innova['user']);

        // 6. Test with a booking status that is NOT 'Disetujui' (e.g. 'Pending')
        RoomVehicleBooking::create([
            'ticket_id' => $this->ticket->id,
            'tipe' => 'ruang',
            'nama_aset' => 'Ruang Meeting C',
            'tanggal_mulai' => $now->copy()->subHour(),
            'tanggal_selesai' => $now->copy()->addHour(),
            'status' => 'Pending',
        ]);

        $response = $this->actingAs($this->user)->get('/monitor');
        $assets = $response->original->getData()['page']['props']['assets'];
        
        $meetingC = collect($assets)->firstWhere('nama_aset', 'Ruang Meeting C');
        $this->assertNotNull($meetingC);
        $this->assertEquals('Tersedia', $meetingC['status']);

        // Reset testing time
        Carbon::setTestNow();
    }
}
