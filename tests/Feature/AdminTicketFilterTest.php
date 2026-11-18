<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SubUnit;
use App\Models\Ticket;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTicketFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_filter_pencarian_dan_tab_terkirim_dan_menyaring(): void
    {
        $admin = Admin::factory()->create(['username' => 'superadmin']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
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
}
