<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketCancelVsRejectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Admin $superAdmin;
    private Admin $unitAdmin;
    private Unit $unit;
    private SubUnit $subUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->unit = Unit::create([
            'nama_unit' => 'Sarpras',
            'deskripsi' => 'Unit Sarpras',
            'aktif' => true,
        ]);
        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Peminjaman Mobil',
            'deskripsi' => 'Kendaraan',
            'aktif' => true,
            'is_monitored' => true,
            'monitor_kategori' => 'Kendaraan',
        ]);

        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);

        $this->superAdmin = Admin::create([
            'username' => 'superadmin_test',
            'email' => 'super@example.com',
            'name' => 'Super Admin',
            'password' => Hash::make('password'),
        ]);
        $this->superAdmin->assignRole('Super Admin');

        $this->unitAdmin = Admin::create([
            'username' => 'admin_sarpras',
            'email' => 'sarpras@example.com',
            'name' => 'Admin Sarpras',
            'password' => Hash::make('password'),
        ]);
        $this->unitAdmin->units()->attach($this->unit->id);
    }

    public function test_web_super_admin_cancelling_others_ticket_sets_status_to_reject(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keperluan' => 'Dinas'],
            'status' => 'open',
        ]);

        $this->actingAs($this->superAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $ticket))
            ->assertRedirect();

        $this->assertEquals('reject', $ticket->fresh()->status);
    }

    public function test_web_admin_cancelling_own_ticket_sets_status_to_dibatalkan(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->unitAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keperluan' => 'Dinas'],
            'status' => 'open',
        ]);

        $this->actingAs($this->unitAdmin, 'admin')
            ->patch(route('admin.tiket.cancel', $ticket))
            ->assertRedirect();

        $this->assertEquals('dibatalkan', $ticket->fresh()->status);
    }

    public function test_web_admin_cancelling_others_booking_sets_status_to_reject(): void
    {
        $ticket = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Kendaraan' => 'APV'],
            'status' => 'on_proses',
        ]);
        $booking = RoomVehicleBooking::create([
            'ticket_id' => $ticket->id,
            'tipe' => 'Kendaraan',
            'nama_aset' => 'APV',
            'tanggal_mulai' => Carbon::now()->addHour(),
            'tanggal_selesai' => Carbon::now()->addHours(3),
            'status' => 'on_proses',
        ]);

        $this->actingAs($this->unitAdmin, 'admin')
            ->patch(route('admin.tiket.cancel-booking', $ticket))
            ->assertRedirect();

        $this->assertEquals('reject', $ticket->fresh()->status);
        $this->assertEquals('reject', $booking->fresh()->status);
    }

    public function test_api_admin_cancelling_others_ticket_or_booking_sets_status_to_reject(): void
    {
        $ticket1 = Ticket::create([
            'user_id' => $this->user->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keperluan' => 'Test'],
            'status' => 'open',
        ]);
        $booking1 = RoomVehicleBooking::create([
            'ticket_id' => $ticket1->id,
            'tipe' => 'Kendaraan',
            'nama_aset' => 'APV',
            'tanggal_mulai' => Carbon::now()->addHour(),
            'tanggal_selesai' => Carbon::now()->addHours(3),
            'status' => 'open',
        ]);

        Sanctum::actingAs($this->unitAdmin, ['*']);

        $this->getJson("/api/tickets/{$ticket1->id}")
            ->assertOk()
            ->assertJsonPath('data.isSubmittedByMe', false)
            ->assertJsonPath('data.hasActiveBooking', true);

        $this->patchJson("/api/tickets/{$ticket1->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'reject');

        $this->assertEquals('reject', $ticket1->fresh()->status);
        $this->assertEquals('reject', $booking1->fresh()->status);

        // Own admin ticket stays dibatalkan
        $ownTicket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->unitAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keperluan' => 'Sendiri'],
            'status' => 'open',
        ]);

        $this->patchJson("/api/tickets/{$ownTicket->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'dibatalkan');

        $this->assertEquals('dibatalkan', $ownTicket->fresh()->status);
    }
}
