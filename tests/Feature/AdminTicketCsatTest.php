<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Csat;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTicketCsatTest extends TestCase
{
    use RefreshDatabase;

    private Admin $requesterAdmin;
    private Admin $otherAdmin;
    private Unit $unit;
    private SubUnit $subUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requesterAdmin = Admin::create([
            'username' => 'admin_pemohon',
            'email' => 'pemohon@example.com',
            'name' => 'Admin Pemohon',
            'password' => Hash::make('password123'),
        ]);

        $this->otherAdmin = Admin::create([
            'username' => 'admin_unit',
            'email' => 'unit@example.com',
            'name' => 'Admin Unit',
            'password' => Hash::make('password123'),
        ]);

        $this->unit = Unit::create([
            'nama_unit' => 'IT Support',
            'deskripsi' => 'Unit IT Support',
            'aktif' => true,
        ]);

        $this->subUnit = SubUnit::create([
            'unit_id' => $this->unit->id,
            'nama_layanan' => 'Perbaikan Jaringan',
            'deskripsi' => 'Perbaikan Jaringan',
            'aktif' => true,
            'is_revision_enabled' => true,
        ]);
    }

    public function test_admin_can_see_pending_csat_and_rate_own_submitted_ticket_without_matching_user(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'WiFi mati'],
            'status' => 'solve',
        ]);

        Sanctum::actingAs($this->requesterAdmin, ['*']);

        // 1. Detail ticket identifies admin as requester
        $this->getJson("/api/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.isSubmittedByMe', true);

        // 2. Pending CSAT lists the solved admin-submitted ticket
        $this->getJson('/api/ratings/pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) $ticket->id);

        // 3. Admin submits rating
        $this->postJson("/api/tickets/{$ticket->id}/rate", [
            'rating' => 5,
            'komentar' => 'Penanganan cepat sekali',
        ])
            ->assertCreated()
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.comment', 'Penanganan cepat sekali');

        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 5,
            'komentar' => 'Penanganan cepat sekali',
        ]);

        // 4. Pending CSAT is now empty
        $this->getJson('/api/ratings/pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // 5. Submitted rating appears under scope=submitted
        $this->getJson('/api/ratings?scope=submitted')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.score', 5);
    }

    public function test_other_admin_cannot_rate_ticket_they_did_not_submit(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'Printer rusak'],
            'status' => 'solve',
        ]);

        Sanctum::actingAs($this->otherAdmin, ['*']);

        // Detail tiket dibatasi untuk admin yang benar-benar berhak; admin luar tidak bisa menebak ID.
        $this->getJson("/api/tickets/{$ticket->id}")->assertForbidden();

        $this->postJson("/api/tickets/{$ticket->id}/rate", [
            'rating' => 4,
        ])->assertForbidden();

        $this->assertEquals(0, Csat::count());
    }

    public function test_admin_can_rate_ticket_linked_by_matching_user_email_and_request_revision(): void
    {
        $linkedUser = User::factory()->create([
            'username' => $this->requesterAdmin->username,
            'email' => $this->requesterAdmin->email,
        ]);

        $ticket = Ticket::create([
            'user_id' => $linkedUser->id,
            'created_by_admin_id' => null,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'AC bocor'],
            'status' => 'solve',
        ]);

        Sanctum::actingAs($this->requesterAdmin, ['*']);

        $this->postJson("/api/tickets/{$ticket->id}/revision", [
            'catatan' => 'Masih menetes airnya',
        ])->assertOk();

        $ticket->refresh();
        $this->assertEquals('need_revision', $ticket->status);

        $ticket->update(['status' => 'solve']);

        $this->postJson("/api/tickets/{$ticket->id}/rate", [
            'rating' => 5,
        ])->assertCreated();

        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticket->id,
            'user_id' => $linkedUser->id,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 5,
        ]);
    }

    public function test_admin_creator_can_submit_and_update_csat_via_web_route(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'Jaringan lambat'],
            'status' => 'solve',
        ]);

        // Submit rating pertama kali
        $this->actingAs($this->requesterAdmin, 'admin')
            ->post(route('admin.tiket.csat.store', $ticket->id), [
                'rating' => 5,
                'komentar' => 'Sangat cepat',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Terima kasih atas rating kamu!');

        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 5,
            'komentar' => 'Sangat cepat',
        ]);
        $this->assertSame('Selesai', $ticket->fresh()->status);

        // Edit rating ulang: update row yang sama (tanpa duplikasi)
        $this->actingAs($this->requesterAdmin, 'admin')
            ->post(route('admin.tiket.csat.store', $ticket->id), [
                'rating' => 4,
                'komentar' => 'Diperbarui setelah cek ulang',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Terima kasih atas rating kamu!');

        $this->assertSame(1, Csat::count());
        $this->assertDatabaseHas('csats', [
            'ticket_id' => $ticket->id,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 4,
            'komentar' => 'Diperbarui setelah cek ulang',
        ]);
    }

    public function test_non_creator_admin_cannot_submit_csat_via_web_route(): void
    {
        $this->otherAdmin->units()->attach($this->unit->id);

        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'assigned_admin_id' => $this->otherAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'Printer macet'],
            'status' => 'solve',
        ]);

        $this->actingAs($this->otherAdmin, 'admin')
            ->post(route('admin.tiket.csat.store', $ticket->id), [
                'rating' => 5,
            ])
            ->assertStatus(403);

        $this->assertSame(0, Csat::count());
    }

    public function test_admin_cannot_submit_csat_for_unsolved_ticket_via_web_route(): void
    {
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'Lampu ruang rapat'],
            'status' => 'on_proses',
        ]);

        $this->actingAs($this->requesterAdmin, 'admin')
            ->post(route('admin.tiket.csat.store', $ticket->id), [
                'rating' => 5,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('rating');

        $this->assertSame(0, Csat::count());
    }

    public function test_admin_ticket_detail_loads_csat_and_is_accessible_across_units_for_creator(): void
    {
        $itUnit = Unit::create([
            'nama_unit' => 'Unit IT Pemohon',
            'deskripsi' => 'IT',
            'aktif' => true,
        ]);
        $this->requesterAdmin->units()->attach($itUnit->id);

        // Tiket ditujukan ke unit lain (Sarpras / $this->unit)
        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'AC ruang server'],
            'status' => 'solve',
        ]);

        Csat::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 5,
            'komentar' => 'Mantap',
        ]);

        $this->actingAs($this->requesterAdmin, 'admin')
            ->get(route('admin.tiket.show', $ticket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Detail')
                ->where('isSubmittedByMe', true)
                ->where('ticket.csat.rating', 5)
                ->where('ticket.csat.komentar', 'Mantap')
            );

        // Pastikan halaman index tab Tiket Saya Ajukan juga memuat csat
        $this->actingAs($this->requesterAdmin, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'saya_ajukan']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('tickets.data.0.csat.rating', 5)
            );
    }

    public function test_admin_csat_report_loads_admin_rater_identity(): void
    {
        Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        Permission::firstOrCreate(['name' => 'akses-laporan', 'guard_name' => 'admin']);
        $this->otherAdmin->assignRole('superadmin');
        $this->otherAdmin->givePermissionTo('akses-laporan');

        $ticket = Ticket::create([
            'user_id' => null,
            'created_by_admin_id' => $this->requesterAdmin->id,
            'unit_id' => $this->unit->id,
            'sub_unit_id' => $this->subUnit->id,
            'form_data' => ['Keluhan' => 'Proyektor'],
            'status' => 'Selesai',
        ]);

        Csat::create([
            'ticket_id' => $ticket->id,
            'user_id' => null,
            'admin_id' => $this->requesterAdmin->id,
            'rating' => 5,
            'komentar' => 'Bagus',
        ]);

        $this->actingAs($this->otherAdmin, 'admin')
            ->get(route('admin.csat.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Csat/Index')
                ->where('csats.data.0.admin.id', $this->requesterAdmin->id)
                ->where('csats.data.0.admin.name', 'Admin Pemohon')
            );
    }
}
