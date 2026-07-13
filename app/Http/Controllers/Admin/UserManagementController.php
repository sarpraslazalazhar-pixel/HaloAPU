<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query();

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($divisi = $request->get('divisi_id')) {
            $query->where('divisi_id', $divisi);
        }

        if ($unitOrg = $request->get('org_unit_id')) {
            $query->where('org_unit_id', $unitOrg);
        }

        $users = $query->with(['divisi', 'orgUnit', 'jabatan'])->latest()->paginate(15);

        $divisiList = \App\Models\OrgDivisi::select('id', 'nama_divisi')->orderBy('nama_divisi')->get();
        $unitOrgList = \App\Models\OrgUnit::select('id', 'nama_unit_organisasi', 'divisi_id')->orderBy('nama_unit_organisasi')->get();
        $jabatanList = \App\Models\OrgJabatan::select('id', 'nama_jabatan')->orderBy('nama_jabatan')->get();

        return Inertia::render('Admin/ManajemenUser/Index', [
            'users' => $users,
            'filters' => $request->only(['search', 'divisi_id', 'org_unit_id']),
            'divisiList' => $divisiList,
            'unitOrgList' => $unitOrgList,
            'jabatanList' => $jabatanList,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'username' => 'required|string|max:50|unique:users,username',
            'email' => 'required|email|max:100|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'no_wa' => 'nullable|string|max:20',
            'divisi_id' => 'nullable|exists:org_divisi,id',
            'org_unit_id' => 'nullable|exists:org_unit,id',
            'jabatan_id' => 'nullable|exists:org_jabatan,id',
        ]);

        User::create([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'no_wa' => $validated['no_wa'],
            'divisi_id' => $validated['divisi_id'],
            'org_unit_id' => $validated['org_unit_id'],
            'jabatan_id' => $validated['jabatan_id'],
        ]);

        return back()->with('success', 'User berhasil ditambahkan.');
    }

    public function update(Request $request, User $manajemen_user)
    {
        $user = $manajemen_user;

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'username' => ['required', 'string', 'max:50', Rule::unique('users')->ignore($user->id)],
            'email' => ['required', 'email', 'max:100', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8|confirmed',
            'no_wa' => 'nullable|string|max:20',
            'divisi_id' => 'nullable|exists:org_divisi,id',
            'org_unit_id' => 'nullable|exists:org_unit,id',
            'jabatan_id' => 'nullable|exists:org_jabatan,id',
        ]);

        $user->update([
            'name' => $validated['name'],
            'username' => $validated['username'],
            'email' => $validated['email'],
            'no_wa' => $validated['no_wa'],
            'divisi_id' => $validated['divisi_id'],
            'org_unit_id' => $validated['org_unit_id'],
            'jabatan_id' => $validated['jabatan_id'],
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        return back()->with('success', 'User berhasil diperbarui.');
    }

    public function destroy(User $manajemen_user)
    {
        $user = $manajemen_user;

        $activeTickets = $user->tickets()->whereNotIn('status', ['Selesai'])->count();
        if ($activeTickets > 0) {
            return back()->withErrors([
                'error' => "User ini memiliki {$activeTickets} tiket aktif. Selesaikan terlebih dahulu.",
            ]);
        }

        $user->delete();

        return back()->with('success', 'User berhasil dihapus.');
    }
}
