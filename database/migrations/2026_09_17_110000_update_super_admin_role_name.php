<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\Models\Role;
use App\Models\Admin;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update existing 'Super Admin' role to 'superadmin'
        DB::table('roles')
            ->where('guard_name', 'admin')
            ->where('name', 'Super Admin')
            ->update(['name' => 'superadmin']);

        // Clear Spatie cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Ensure superadmin role exists and is assigned to superadmin admin account
        $role = Role::firstOrCreate(['name' => 'superadmin', 'guard_name' => 'admin']);
        $admin = Admin::where('username', 'superadmin')->first();
        if ($admin && !$admin->hasRole('superadmin')) {
            $admin->assignRole($role);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('roles')
            ->where('guard_name', 'admin')
            ->where('name', 'superadmin')
            ->update(['name' => 'Super Admin']);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
