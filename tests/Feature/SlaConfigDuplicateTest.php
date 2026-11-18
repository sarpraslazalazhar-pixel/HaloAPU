<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SlaConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaConfigDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplikat_store_ditolak_dengan_errors_message(): void
    {
        $admin = Admin::factory()->create(['username' => 'superadmin']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        $admin->assignRole('Super Admin');

        SlaConfig::create(['sub_unit_id' => null, 'priority' => 'Rendah', 'jenis' => 'respon', 'threshold_minutes' => 30]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.sla-config.store'), [
                'sub_unit_id' => null,
                'priority' => 'Rendah',
                'jenis' => 'respon',
                'threshold_minutes' => 45,
            ])
            ->assertSessionHasErrors(['message']);

        $this->assertSame(1, SlaConfig::count());
    }
}
