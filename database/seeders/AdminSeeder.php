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
        DB::table('roles')->where('guard_name', 'admin')->delete();
        DB::table('model_has_roles')->where('model_type', 'App\Models\Admin')->delete();

        Role::create(['name' => 'admin', 'guard_name' => 'admin']);
        Role::create(['name' => 'operator', 'guard_name' => 'admin']);

        $admin = Admin::create([
            'username' => 'superadmin',
            'email' => 'admin@haloapu.test',
            'password' => Hash::make('password'),
        ]);
        $admin->assignRole('admin');

        $operator = Admin::create([
            'username' => 'operator1',
            'email' => 'operator1@haloapu.test',
            'password' => Hash::make('password'),
        ]);
        $operator->assignRole('operator');
    }
}
