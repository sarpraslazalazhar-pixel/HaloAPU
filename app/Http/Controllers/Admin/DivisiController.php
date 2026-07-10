<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgDivisi;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DivisiController extends Controller
{
    public function index(Request $request)
    {
        $query = OrgDivisi::withCount('orgUnits');

        if ($request->has('search')) {
            $query->where('nama_divisi', 'like', '%' . $request->search . '%');
        }

        $divisis = $query->orderBy('nama_divisi')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/Divisi/Index', [
            'divisis' => $divisis,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_divisi' => 'required|string|max:255|unique:org_divisi,nama_divisi',
        ]);

        OrgDivisi::create($validated);

        return redirect()->back()->with('success', 'Divisi berhasil ditambahkan.');
    }

    public function update(Request $request, OrgDivisi $divisi)
    {
        $validated = $request->validate([
            'nama_divisi' => 'required|string|max:255|unique:org_divisi,nama_divisi,' . $divisi->id,
        ]);

        $divisi->update($validated);

        return redirect()->back()->with('success', 'Divisi berhasil diupdate.');
    }

    public function destroy(OrgDivisi $divisi)
    {
        if ($divisi->orgUnits()->count() > 0) {
            return redirect()->back()->with('error', 'Divisi tidak bisa dihapus karena masih memiliki Unit Organisasi.');
        }

        $divisi->delete();
        return redirect()->back()->with('success', 'Divisi berhasil dihapus.');
    }
}
