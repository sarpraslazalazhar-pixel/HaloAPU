<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleManagementController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::with('permissions')->where('guard_name', 'admin')->get();
        $permissions = Permission::where('guard_name', 'admin')->get();

        return Inertia::render('Admin/ManajemenPeran/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
            'permissions' => 'array',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'admin',
        ]);

        if (!empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return back()->with('success', "Role {$role->name} berhasil ditambahkan.");
    }

    public function update(Request $request, Role $manajemen_peran)
    {
        $role = $manajemen_peran;

        if ($role->name === 'Super Admin') {
            return back()->with('error', 'Role Super Admin tidak dapat diubah.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name,' . $role->id,
            'permissions' => 'array',
        ]);

        $role->update(['name' => $validated['name']]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        return back()->with('success', "Role {$role->name} berhasil diperbarui.");
    }

    public function destroy(Role $manajemen_peran)
    {
        $role = $manajemen_peran;

        if ($role->name === 'Super Admin') {
            return back()->with('error', 'Role Super Admin tidak dapat dihapus.');
        }

        $role->delete();

        return back()->with('success', "Role berhasil dihapus.");
    }
}
