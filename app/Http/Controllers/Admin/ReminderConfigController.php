<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReminderConfig;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ReminderConfigController extends Controller
{
    public function index()
    {
        $configs = ReminderConfig::orderBy('jenis_reminder')->get();

        return Inertia::render('Admin/ReminderConfig/Index', [
            'configs' => $configs,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'configs' => 'required|array|min:1',
            'configs.*.id' => 'required|exists:reminder_configs,id',
            'configs.*.lead_time_value' => 'required|integer|min:0',
            'configs.*.channel_aktif' => 'required|array',
            'configs.*.channel_aktif.*' => 'in:in_app,email,whatsapp',
            'configs.*.aktif' => 'required|boolean',
        ]);

        foreach ($validated['configs'] as $configData) {
            ReminderConfig::where('id', $configData['id'])->update([
                'lead_time_value' => $configData['lead_time_value'],
                'channel_aktif' => $configData['channel_aktif'],
                'aktif' => $configData['aktif'],
            ]);
        }

        return back()->with('success', 'Konfigurasi reminder berhasil disimpan.');
    }
}
