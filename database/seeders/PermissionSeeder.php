<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class PermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            'akses-layanan',
            'akses-struktur',
            'akses-konfigurasi',
            'akses-laporan',
            'akses-manajemen-akun',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'admin']);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        $admin = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'admin']);
        $operator = Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        // Admin gets all permissions by default (or Super Admin via Gate::before if we set it up)
        $admin->syncPermissions($permissions);
    }
}
