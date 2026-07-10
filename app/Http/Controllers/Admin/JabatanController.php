<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrgJabatan;
use Illuminate\Http\Request;
use Inertia\Inertia;

class JabatanController extends Controller
{
    public function index(Request $request)
    {
        $query = OrgJabatan::query();

        if ($request->has('search')) {
            $query->where('nama_jabatan', 'like', '%' . $request->search . '%');
        }

        $jabatans = $query->orderBy('urutan')->orderBy('id')->get();

        return Inertia::render('Admin/MasterData/Jabatan/Index', [
            'jabatans' => $jabatans,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_jabatan' => 'required|string|max:255|unique:org_jabatan,nama_jabatan',
        ]);

        OrgJabatan::create($validated);

        return redirect()->back()->with('success', 'Jabatan berhasil ditambahkan.');
    }

    public function update(Request $request, OrgJabatan $jabatan)
    {
        $validated = $request->validate([
            'nama_jabatan' => 'required|string|max:255|unique:org_jabatan,nama_jabatan,' . $jabatan->id,
        ]);

        $jabatan->update($validated);

        return redirect()->back()->with('success', 'Jabatan berhasil diupdate.');
    }

    public function destroy(OrgJabatan $jabatan)
    {
        $jabatan->delete();
        return redirect()->back()->with('success', 'Jabatan berhasil dihapus.');
    }

    public function reorder(Request $request)
    {
        $request->validate([
            'order' => 'required|array',
            'order.*.id' => 'required|exists:org_jabatan,id',
            'order.*.urutan' => 'required|integer',
        ]);

        foreach ($request->order as $item) {
            OrgJabatan::where('id', $item['id'])->update(['urutan' => $item['urutan']]);
        }

        return redirect()->back();
    }
}
