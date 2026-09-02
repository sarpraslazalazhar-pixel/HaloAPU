<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('admins')->truncate();
        // Skip truncating roles since PermissionSeeder already created them and might cause foreign key issues or delete existing roles
        // DB::table('roles')->where('guard_name', 'admin')->delete();
        DB::table('model_has_roles')->where('model_type', 'App\Models\Admin')->delete();

        // Ensure roles exist in case PermissionSeeder was not run
        Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'admin']);
        Role::firstOrCreate(['name' => 'Operator', 'guard_name' => 'admin']);

        $admin = Admin::create([
            'username' => 'superadmin',
            'email' => 'admin@haloapu.test',
            'password' => Hash::make('password'),
            'name' => 'Super Administrator'
        ]);
        $admin->assignRole('Super Admin');

        $operator = Admin::create([
            'username' => 'operator1',
            'email' => 'operator1@haloapu.test',
            'password' => Hash::make('password'),
            'name' => 'Operator Pertama'
        ]);
        $operator->assignRole('Operator');
    }
}
