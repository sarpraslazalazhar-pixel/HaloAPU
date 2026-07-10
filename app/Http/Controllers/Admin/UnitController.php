<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $query = Unit::withCount('subUnits');

        if ($request->has('search')) {
            $query->where('nama_unit', 'like', '%' . $request->search . '%');
        }

        $units = $query->orderBy('nama_unit')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Admin/MasterData/Unit/Index', [
            'units' => $units,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_unit' => 'required|string|max:255|unique:units,nama_unit',
            'deskripsi' => 'nullable|string',
            'aktif' => 'nullable|boolean'
        ]);

        if ($request->has('aktif')) {
            $validated['aktif'] = $request->boolean('aktif');
        }

        Unit::create($validated);

        return redirect()->back()->with('success', 'Unit berhasil ditambahkan.');
    }

    public function update(Request $request, Unit $unit)
    {
        $validated = $request->validate([
            'nama_unit' => 'required|string|max:255|unique:units,nama_unit,' . $unit->id,
            'deskripsi' => 'nullable|string',
            'aktif' => 'nullable|boolean'
        ]);

        if ($request->has('aktif')) {
            $validated['aktif'] = $request->boolean('aktif');
        }

        $unit->update($validated);

        return redirect()->back()->with('success', 'Unit berhasil diupdate.');
    }

    public function destroy(Unit $unit)
    {
        if ($unit->subUnits()->count() > 0) {
            return redirect()->back()->with('error', 'Unit tidak bisa dihapus karena masih memiliki Sub Unit.');
        }

        $unit->delete();
        return redirect()->back()->with('success', 'Unit berhasil dihapus.');
    }
}
