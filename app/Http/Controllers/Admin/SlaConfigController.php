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
    public function index()
    {
        $globalConfigs = SlaConfig::whereNull('sub_unit_id')
            ->orderBy('jenis')
            ->orderBy('priority')
            ->get();

        $subUnits = SubUnit::with(['unit', 'slaConfigs' => function ($q) {
            $q->orderBy('jenis')->orderBy('priority');
        }])->get();

        return Inertia::render('Admin/SlaConfig/Index', [
            'globalConfigs' => $globalConfigs,
            'subUnits' => $subUnits,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'configs' => 'required|array|min:1',
            'configs.*.sub_unit_id' => 'nullable|exists:sub_units,id',
            'configs.*.priority' => ['required', Rule::in(['Rendah', 'Sedang', 'Tinggi', 'Urgen'])],
            'configs.*.jenis' => ['required', Rule::in(['respon', 'penyelesaian'])],
            'configs.*.threshold_minutes' => 'required|integer|min:1',
        ]);

        $keepIds = [];
        foreach ($validated['configs'] as $config) {
            $record = SlaConfig::updateOrCreate(
                [
                    'sub_unit_id' => $config['sub_unit_id'],
                    'priority' => $config['priority'],
                    'jenis' => $config['jenis'],
                ],
                [
                    'threshold_minutes' => $config['threshold_minutes'],
                ]
            );
            $keepIds[] = $record->id;
        }

        // Hapus konfigurasi yang tidak ada di payload (misalnya karena override dihapus)
        SlaConfig::whereNotIn('id', $keepIds)->delete();

        return back()->with('success', 'Konfigurasi SLA berhasil disimpan.');
    }
}
