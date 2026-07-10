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
            ->orderBy('tier')
            ->get();

        $subUnits = SubUnit::with(['unit', 'slaConfigs' => function ($q) {
            $q->orderBy('jenis')->orderBy('tier');
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
            'configs.*.tier' => 'required|integer|in:1,2,3',
            'configs.*.jenis' => ['required', Rule::in(['respon', 'penyelesaian'])],
            'configs.*.threshold_minutes' => 'required|integer|min:1',
        ]);

        $grouped = collect($validated['configs'])
            ->groupBy(fn ($c) => ($c['sub_unit_id'] ?? 'global') . '_' . $c['jenis']);

        foreach ($grouped as $key => $items) {
            $sorted = $items->sortBy('tier')->values();
            for ($i = 1; $i < $sorted->count(); $i++) {
                if ($sorted[$i]['threshold_minutes'] <= $sorted[$i - 1]['threshold_minutes']) {
                    return back()->withErrors([
                        'configs' => "Threshold Tier {$sorted[$i]['tier']} harus lebih besar dari Tier {$sorted[$i-1]['tier']} untuk grup {$key}.",
                    ]);
                }
            }
        }

        foreach ($validated['configs'] as $config) {
            SlaConfig::updateOrCreate(
                [
                    'sub_unit_id' => $config['sub_unit_id'],
                    'tier' => $config['tier'],
                    'jenis' => $config['jenis'],
                ],
                [
                    'threshold_minutes' => $config['threshold_minutes'],
                ]
            );
        }

        return back()->with('success', 'Konfigurasi SLA berhasil disimpan.');
    }
}
