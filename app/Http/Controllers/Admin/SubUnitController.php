<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubUnit;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SubUnitController extends Controller
{
    public function index(Request $request)
    {
        $query = SubUnit::with('unit')->withCount('formFields');

        if ($request->has('search')) {
            $query->where('nama_layanan', 'like', '%' . $request->search . '%');
        }

        if ($request->has('unit_id') && $request->unit_id) {
            $query->where('unit_id', $request->unit_id);
        }

        $subUnits = $query->orderBy('nama_layanan')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/SubUnit/Index', [
            'subUnits' => $subUnits,
            'units' => Unit::where('aktif', true)->orderBy('nama_unit')->get(),
            'filters' => $request->only('search', 'unit_id'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'nama_layanan' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'aktif' => 'nullable|boolean'
        ]);

        if ($request->has('aktif')) {
            $validated['aktif'] = $request->boolean('aktif');
        }

        SubUnit::create($validated);

        return redirect()->back()->with('success', 'Sub Unit berhasil ditambahkan.');
    }

    public function update(Request $request, SubUnit $subUnit)
    {
        $validated = $request->validate([
            'unit_id' => 'required|exists:units,id',
            'nama_layanan' => 'required|string|max:255',
            'deskripsi' => 'nullable|string',
            'aktif' => 'nullable|boolean'
        ]);

        if ($request->has('aktif')) {
            $validated['aktif'] = $request->boolean('aktif');
        }

        $subUnit->update($validated);

        return redirect()->back()->with('success', 'Sub Unit berhasil diupdate.');
    }

    public function destroy(SubUnit $subUnit)
    {
        if ($subUnit->formFields()->count() > 0) {
            return redirect()->back()->with('error', 'Sub Unit tidak bisa dihapus karena masih memiliki Form Field.');
        }

        $subUnit->delete();
        return redirect()->back()->with('success', 'Sub Unit berhasil dihapus.');
    }
}
