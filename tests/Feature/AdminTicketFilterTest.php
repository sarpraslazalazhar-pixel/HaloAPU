<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminTicketFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_pencarian_dan_tab_terkirim_dan_menyaring(): void
    {
        $admin = Admin::factory()->create(['username' => 'superadmin']);
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        $admin->assignRole('Super Admin');

        $user = User::factory()->create();
        $unit = Unit::create(['nama_unit' => 'Umum', 'deskripsi' => 'Unit', 'aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Kerusakan Umum',
            'deskripsi' => 'Layanan umum',
            'aktif' => true,
        ]);

        foreach (['AC bocor', 'Lampu mati'] as $judul) {
            Ticket::create([
                'user_id' => $user->id,
                'sub_unit_id' => $subUnit->id,
                'unit_id' => $unit->id,
                'form_data' => ['1' => $judul],
                'status' => 'open',
                'priority' => 'normal',
                'source' => 'web',
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tiket.index', ['search' => 'Kerusakan', 'tab' => 'masuk']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('filters.search', 'Kerusakan')
                ->has('tickets.data', 2)
            );

        $this->actingAs($admin, 'admin')
            ->get(route('admin.tiket.index', ['search' => 'tidak-ada-yang-cocok', 'tab' => 'masuk']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
    }

    public function test_filter_penugasan_dan_status_ditugaskan_ke_saya(): void
    {
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        $unit = Unit::create(['nama_unit' => 'IT Support', 'deskripsi' => 'Unit IT', 'aktif' => true]);
        $subUnit = SubUnit::create([
            'unit_id' => $unit->id,
            'nama_layanan' => 'Perbaikan Laptop',
            'deskripsi' => 'Perbaikan',
            'aktif' => true,
        ]);

        $operatorA = Admin::factory()->create(['username' => 'operator_a', 'name' => 'Operator A']);
        $operatorA->assignRole('Operator');
        $operatorA->units()->attach($unit->id);

        $operatorB = Admin::factory()->create(['username' => 'operator_b', 'name' => 'Operator B']);
        $operatorB->assignRole('Operator');
        $operatorB->units()->attach($unit->id);

        $otherUnit = Unit::create(['nama_unit' => 'Keuangan', 'deskripsi' => 'Unit Keuangan', 'aktif' => true]);
        $unrelatedOperator = Admin::factory()->create(['username' => 'operator_keuangan', 'name' => 'Operator Keuangan']);
        $unrelatedOperator->assignRole('Operator');
        $unrelatedOperator->units()->attach($otherUnit->id);

        $requester = User::factory()->create();

        $myTicket = Ticket::create([
            'user_id' => $requester->id,
            'sub_unit_id' => $subUnit->id,
            'unit_id' => $unit->id,
            'form_data' => ['1' => 'Tiket milik A'],
            'status' => 'on_proses',
            'priority' => 'Sedang',
            'assigned_admin_id' => $operatorA->id,
            'source' => 'web',
        ]);

        $otherTicket = Ticket::create([
            'user_id' => $requester->id,
            'sub_unit_id' => $subUnit->id,
            'unit_id' => $unit->id,
            'form_data' => ['1' => 'Tiket milik B'],
            'status' => 'on_proses',
            'priority' => 'Sedang',
            'assigned_admin_id' => $operatorB->id,
            'source' => 'web',
        ]);

        $unassignedTicket = Ticket::create([
            'user_id' => $requester->id,
            'sub_unit_id' => $subUnit->id,
            'unit_id' => $unit->id,
            'form_data' => ['1' => 'Tiket belum ditugaskan'],
            'status' => 'open',
            'priority' => 'Sedang',
            'assigned_admin_id' => null,
            'source' => 'web',
        ]);

        // Tiket selesai tidak ikut dihitung di tab masuk default.
        Ticket::create([
            'user_id' => $requester->id,
            'sub_unit_id' => $subUnit->id,
            'unit_id' => $unit->id,
            'form_data' => ['1' => 'Tiket selesai A'],
            'status' => 'solve',
            'priority' => 'Sedang',
            'assigned_admin_id' => $operatorA->id,
            'source' => 'web',
        ]);

        // Tanpa filter assignment: tampil semua tiket aktif unit, operators berisi operator se-unit (bukan unit lain)
        $this->actingAs($operatorA, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'masuk']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('assignedToMeCount', 1)
                ->has('tickets.data', 3)
                ->has('operators', 2)
                ->where('operators.0.id', $operatorA->id)
                ->where('operators.1.id', $operatorB->id)
            );

        // Filter assignment=<id operatorB>: hanya tiket yang ditugaskan ke operatorB, assignedToMeCount tetap 1
        $this->actingAs($operatorA, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'masuk', 'assignment' => (string) $operatorB->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('filters.assignment', (string) $operatorB->id)
                ->where('assignedToMeCount', 1)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $otherTicket->id)
                ->where('tickets.data.0.assigned_admin_id', $operatorB->id)
            );

        // Filter assignment=<id operatorA> atau me: hanya tiket yang ditugaskan ke operatorA
        $this->actingAs($operatorA, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'masuk', 'assignment' => (string) $operatorA->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('filters.assignment', (string) $operatorA->id)
                ->where('assignedToMeCount', 1)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $myTicket->id)
            );

        // Filter assignment=me: tetap kompatibel untuk shortcut Ditugaskan ke Saya
        $this->actingAs($operatorA, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'masuk', 'assignment' => 'me']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('filters.assignment', 'me')
                ->where('assignedToMeCount', 1)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $myTicket->id)
                ->where('tickets.data.0.assigned_admin_id', $operatorA->id)
            );

        // Filter assignment=unassigned: hanya tiket yang belum ditugaskan, assignedToMeCount tetap 1
        $this->actingAs($operatorA, 'admin')
            ->get(route('admin.tiket.index', ['tab' => 'masuk', 'assignment' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Index')
                ->where('filters.assignment', 'unassigned')
                ->where('assignedToMeCount', 1)
                ->has('tickets.data', 1)
                ->where('tickets.data.0.id', $unassignedTicket->id)
            );

        // Halaman detail tiket menandai isAssignedToMe & memuat relasi assignedAdmin
        $this->actingAs($operatorA, 'admin')
            ->get(route('admin.tiket.show', $myTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Detail')
                ->where('isAssignedToMe', true)
                ->where('ticket.assigned_admin_id', $operatorA->id)
                ->where('ticket.assigned_admin.id', $operatorA->id)
            );

        $this->actingAs($operatorA, 'admin')
            ->get(route('admin.tiket.show', $otherTicket->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Tiketing/Detail')
                ->where('isAssignedToMe', false)
                ->where('ticket.assigned_admin_id', $operatorB->id)
                ->where('ticket.assigned_admin.id', $operatorB->id)
            );
    }
}
