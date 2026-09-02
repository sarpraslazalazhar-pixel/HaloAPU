<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SlaConfig;
use App\Models\SubUnit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SlaConfigController extends Controller
{
    public function index(Request $request)
    {
        $query = SlaConfig::with('subUnit.unit')->orderBy('id', 'desc');

        if ($request->has('search') && $request->search != '') {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('jenis', 'like', "%{$search}%")
                  ->orWhere('priority', 'like', "%{$search}%")
                  ->orWhereHas('subUnit', function ($sq) use ($search) {
                      $sq->where('nama_layanan', 'like', "%{$search}%");
                  });
            });
        }

        $configs = $query->paginate(10)->withQueryString();
        $subUnits = SubUnit::with('unit')->get();

        return Inertia::render('Admin/SlaConfig/Index', [
            'configs' => $configs,
            'subUnits' => $subUnits,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sub_unit_id' => 'nullable|exists:sub_units,id',
            'priority' => ['required', Rule::in(['Rendah', 'Sedang', 'Tinggi', 'Urgen'])],
            'jenis' => ['required', Rule::in(['respon', 'penyelesaian'])],
            'threshold_minutes' => 'required|integer|min:1',
        ]);

        // Validasi Unique
        $exists = SlaConfig::where('sub_unit_id', $validated['sub_unit_id'])
            ->where('priority', $validated['priority'])
            ->where('jenis', $validated['jenis'])
            ->exists();

        if ($exists) {
            return back()->withErrors(['message' => 'Konfigurasi untuk Sub Unit, Prioritas, dan Jenis ini sudah ada.']);
        }

        SlaConfig::create($validated);

        return back()->with('success', 'Konfigurasi SLA berhasil ditambahkan.');
    }

    public function update(Request $request, SlaConfig $sla_config)
    {
        $validated = $request->validate([
            'sub_unit_id' => 'nullable|exists:sub_units,id',
            'priority' => ['required', Rule::in(['Rendah', 'Sedang', 'Tinggi', 'Urgen'])],
            'jenis' => ['required', Rule::in(['respon', 'penyelesaian'])],
            'threshold_minutes' => 'required|integer|min:1',
        ]);

        // Validasi Unique kecuali ID sendiri
        $exists = SlaConfig::where('sub_unit_id', $validated['sub_unit_id'])
            ->where('priority', $validated['priority'])
            ->where('jenis', $validated['jenis'])
            ->where('id', '!=', $sla_config->id)
            ->exists();

        if ($exists) {
            return back()->withErrors(['message' => 'Konfigurasi untuk Sub Unit, Prioritas, dan Jenis ini sudah ada.']);
        }

        $sla_config->update($validated);

        return back()->with('success', 'Konfigurasi SLA berhasil diperbarui.');
    }

    public function destroy(SlaConfig $sla_config)
    {
        $sla_config->delete();

        return back()->with('success', 'Konfigurasi SLA berhasil dihapus.');
    }
}
