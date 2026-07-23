<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = Admin::with(['roles', 'subUnits', 'units']);

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $admins = $query->latest()->paginate(15);
        $roles = \Spatie\Permission\Models\Role::where('guard_name', 'admin')->get();
        $subUnits = \App\Models\SubUnit::with('unit')->get();
        $units = \App\Models\Unit::where('aktif', true)->orderBy('nama_unit')->get();

        return Inertia::render('Admin/ManajemenOperator/Index', [
            'admins' => $admins,
            'roles' => $roles,
            'subUnits' => $subUnits,
            'units' => $units,
            'filters' => $request->only(['search', 'role']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:admins,username',
            'email' => 'required|email|max:100|unique:admins,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|string|exists:roles,name',
            'name' => 'required|string|max:100',
            'no_wa' => 'nullable|string|max:20',
            'sub_units' => 'nullable|array',
            'sub_units.*' => 'exists:sub_units,id',
            'units' => 'nullable|array',
            'units.*' => 'exists:units,id',
        ]);

        $admin = Admin::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'name' => $validated['name'],
            'no_wa' => $validated['no_wa'] ?? null,
        ]);

        $admin->assignRole($validated['role']);
        
        if (isset($validated['sub_units'])) {
            $admin->subUnits()->sync($validated['sub_units']);
        }

        if (isset($validated['units'])) {
            $admin->units()->sync($validated['units']);
        }

        return back()->with('success', "Operator {$admin->username} berhasil ditambahkan.");
    }

    public function update(Request $request, Admin $manajemen_operator)
    {
        $admin = $manajemen_operator;

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', Rule::unique('admins')->ignore($admin->id)],
            'email' => ['required', 'email', 'max:100', Rule::unique('admins')->ignore($admin->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|string|exists:roles,name',
            'name' => 'required|string|max:100',
            'no_wa' => 'nullable|string|max:20',
            'sub_units' => 'nullable|array',
            'sub_units.*' => 'exists:sub_units,id',
            'units' => 'nullable|array',
            'units.*' => 'exists:units,id',
        ]);

        $admin->update([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'name' => $validated['name'],
            'no_wa' => $validated['no_wa'] ?? null,
        ]);

        if (!empty($validated['password'])) {
            $admin->update(['password' => Hash::make($validated['password'])]);
        }

        $admin->syncRoles([$validated['role']]);
        
        if (isset($validated['sub_units'])) {
            $admin->subUnits()->sync($validated['sub_units']);
        } else {
            $admin->subUnits()->detach();
        }

        if (isset($validated['units'])) {
            $admin->units()->sync($validated['units']);
        } else {
            $admin->units()->detach();
        }

        return back()->with('success', "Operator {$admin->username} berhasil diperbarui.");
    }

    public function destroy(Admin $manajemen_operator)
    {
        $admin = $manajemen_operator;

        if ($admin->id === auth('admin')->id()) {
            return back()->withErrors(['error' => 'Anda tidak bisa menghapus akun Anda sendiri.']);
        }

        $admin->delete();

        return back()->with('success', "Operator {$admin->username} berhasil dihapus.");
    }
}
