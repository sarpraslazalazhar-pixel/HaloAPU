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
        $query = Admin::with('roles');

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        $admins = $query->latest()->paginate(15);

        return Inertia::render('Admin/ManajemenAdmin/Index', [
            'admins' => $admins,
            'filters' => $request->only(['search', 'role']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:admins,username',
            'email' => 'required|email|max:100|unique:admins,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|in:Admin,Operator',
            'name' => 'required|string|max:100',
            'no_wa' => 'nullable|string|max:20',
        ]);

        $admin = Admin::create([
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'name' => $validated['name'],
            'no_wa' => $validated['no_wa'] ?? null,
        ]);

        $admin->assignRole($validated['role']);

        return back()->with('success', "Admin {$admin->username} berhasil ditambahkan.");
    }

    public function update(Request $request, Admin $manajemen_admin)
    {
        $admin = $manajemen_admin;

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', Rule::unique('admins')->ignore($admin->id)],
            'email' => ['required', 'email', 'max:100', Rule::unique('admins')->ignore($admin->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|in:Admin,Operator',
            'name' => 'required|string|max:100',
            'no_wa' => 'nullable|string|max:20',
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

        return back()->with('success', "Admin {$admin->username} berhasil diperbarui.");
    }

    public function destroy(Admin $manajemen_admin)
    {
        $admin = $manajemen_admin;

        if ($admin->id === auth('admin')->id()) {
            return back()->withErrors(['error' => 'Anda tidak bisa menghapus akun Anda sendiri.']);
        }

        $admin->delete();

        return back()->with('success', "Admin {$admin->username} berhasil dihapus.");
    }
}
