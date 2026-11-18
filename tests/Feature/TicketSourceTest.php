<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\FormField;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketSourceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Admin $admin;
    private SubUnit $subUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->admin = Admin::factory()->create(['username' => 'operator1']);

        $unit = Unit::create(['nama_unit' => 'Umum', 'deskripsi' => 'Unit', 'aktif' => true]);
        $this->subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Kerusakan Umum',
            'deskripsi' => 'Layanan umum',
            'aktif' => true,
            'is_monitored' => false,
        ]);
        FormField::create([
            'sub_unit_id' => $this->subUnit->id,
            'label' => 'Keterangan',
            'tipe_field' => 'teks_pendek',
            'wajib' => true,
            'urutan' => 1,
        ]);
    }

    public function test_api_store_menandai_tiket_sebagai_mobile(): void
    {
        Sanctum::actingAs($this->user, ['*']);

        $this->postJson('/api/tickets', [
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['1' => 'AC bocor'],
        ])->assertCreated();

        $this->assertSame('mobile', Ticket::firstOrFail()->source);
    }

    public function test_api_dashboard_mengirim_source_dan_assigned_to_me(): void
    {
        $aktif = Ticket::create([
            'user_id' => $this->user->id,
            'sub_unit_id' => $this->subUnit->id,
            'unit_id' => $this->subUnit->unit_id,
            'form_data' => ['1' => 'AC bocor'],
            'status' => 'open',
            'priority' => 'normal',
            'source' => 'mobile',
            'assigned_admin_id' => $this->admin->id,
        ]);
        $selesai = Ticket::create([
            'user_id' => $this->user->id,
            'sub_unit_id' => $this->subUnit->id,
            'unit_id' => $this->subUnit->unit_id,
            'form_data' => ['1' => 'Lampu mati'],
            'status' => 'solve',
            'priority' => 'normal',
            'source' => 'web',
            'assigned_admin_id' => $this->admin->id,
        ]);
        $selesai->forceFill(['created_at' => now()->subHour()])->save();

        Sanctum::actingAs($this->admin, ['*']);
        $res = $this->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame('mobile', $res['recentTickets'][0]['source']);
        $this->assertCount(1, $res['assignedToMe']);
        $this->assertSame($aktif->formatted_id, $res['assignedToMe'][0]['id']);
        $this->assertSame('mobile', $res['assignedToMe'][0]['source']);
    }
}
