<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgUnit;
use App\Models\OrgDivisi;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnitOrganisasiController extends Controller
{
    public function index(Request $request)
    {
        $query = OrgUnit::with('divisi');

        if ($request->has('search')) {
            $query->where('nama_unit_organisasi', 'like', '%' . $request->search . '%');
        }

        if ($request->has('divisi_id') && $request->divisi_id) {
            $query->where('divisi_id', $request->divisi_id);
        }

        $unitOrganisasis = $query->orderBy('nama_unit_organisasi')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/UnitOrganisasi/Index', [
            'unitOrganisasis' => $unitOrganisasis,
            'divisis' => OrgDivisi::orderBy('nama_divisi')->get(),
            'filters' => $request->only('search', 'divisi_id'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_unit_organisasi' => 'required|string|max:255',
            'divisi_id' => 'required|exists:org_divisi,id',
        ]);

        OrgUnit::create($validated);

        return redirect()->back()->with('success', 'Unit Organisasi berhasil ditambahkan.');
    }

    public function update(Request $request, OrgUnit $unitOrganisasi)
    {
        $validated = $request->validate([
            'nama_unit_organisasi' => 'required|string|max:255',
            'divisi_id' => 'required|exists:org_divisi,id',
        ]);

        $unitOrganisasi->update($validated);

        return redirect()->back()->with('success', 'Unit Organisasi berhasil diupdate.');
    }

    public function destroy(OrgUnit $unitOrganisasi)
    {
        $unitOrganisasi->delete();
        return redirect()->back()->with('success', 'Unit Organisasi berhasil dihapus.');
    }
}
