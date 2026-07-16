<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SystemConfigController extends Controller
{
    public function index()
    {
        $configs = [
            'nama_sistem' => SystemConfig::getValue('nama_sistem', 'Halo APU'),
            'logo_path' => SystemConfig::getValue('logo_path'),
            'favicon_path' => SystemConfig::getValue('favicon_path'),
            'banner_path' => SystemConfig::getValue('banner_path'),
            'notification_sound_path' => SystemConfig::getValue('notification_sound_path'),
            'email_admin' => SystemConfig::getValue('email_admin'),
            'wa_api_key' => SystemConfig::getValue('wa_api_key'),
            'wa_number_key' => SystemConfig::getValue('wa_number_key'),
            'wa_gateway_url' => SystemConfig::getValue('wa_gateway_url'),
            'nomor_wa_utama' => SystemConfig::getValue('nomor_wa_utama'),
            'nomor_wa_fallback' => SystemConfig::getValue('nomor_wa_fallback'),
            'app_timezone' => SystemConfig::getValue('app_timezone', 'Asia/Jakarta'),
            'jam_kerja' => SystemConfig::getValue('jam_kerja', [
                'senin' => ['08:00', '16:00'],
                'selasa' => ['08:00', '16:00'],
                'rabu' => ['08:00', '16:00'],
                'kamis' => ['08:00', '16:00'],
                'jumat' => ['08:00', '16:00'],
                'sabtu' => null,
                'minggu' => null,
            ]),
        ];

        return Inertia::render('Admin/Konfigurasi/Index', [
            'configs' => $configs,
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'nama_sistem' => 'required|string|max:100',
            'email_admin' => 'nullable|email|max:100',
            'wa_api_key' => 'nullable|string|max:255',
            'wa_number_key' => 'nullable|string|max:255',
            'wa_gateway_url' => 'nullable|string|max:255',
            'nomor_wa_utama' => 'nullable|string|max:20',
            'nomor_wa_fallback' => 'nullable|string|max:20',
            'app_timezone' => 'required|string|max:50|timezone',
            'jam_kerja' => 'required|array',
            'jam_kerja.senin' => 'nullable|array|size:2',
            'jam_kerja.selasa' => 'nullable|array|size:2',
            'jam_kerja.rabu' => 'nullable|array|size:2',
            'jam_kerja.kamis' => 'nullable|array|size:2',
            'jam_kerja.jumat' => 'nullable|array|size:2',
            'jam_kerja.sabtu' => 'nullable|array|size:2',
            'jam_kerja.minggu' => 'nullable|array|size:2',
        ]);

        SystemConfig::setValue('nama_sistem', $validated['nama_sistem']);
        SystemConfig::setValue('email_admin', $validated['email_admin']);
        SystemConfig::setValue('wa_api_key', $validated['wa_api_key']);
        SystemConfig::setValue('wa_number_key', $validated['wa_number_key']);
        SystemConfig::setValue('wa_gateway_url', $validated['wa_gateway_url']);
        SystemConfig::setValue('nomor_wa_utama', $validated['nomor_wa_utama']);
        SystemConfig::setValue('nomor_wa_fallback', $validated['nomor_wa_fallback']);
        SystemConfig::setValue('app_timezone', $validated['app_timezone']);
        SystemConfig::setValue('jam_kerja', $validated['jam_kerja']);

        return back()->with('success', 'Konfigurasi berhasil disimpan.');
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'logo' => 'required|image|mimes:png,jpg,jpeg,svg|max:2048',
        ]);

        $oldPath = SystemConfig::getValue('logo_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('logo');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/' . $filename, file_get_contents($file->getPathname()));
        $path = 'branding/' . $filename;
        
        SystemConfig::setValue('logo_path', $path);

        return back()->with('success', 'Logo berhasil diunggah.');
    }

    public function uploadBanner(Request $request)
    {
        $request->validate([
            'banner' => 'required|image|mimes:png,jpg,jpeg|max:5120',
        ]);

        $oldPath = SystemConfig::getValue('banner_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('banner');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/' . $filename, file_get_contents($file->getPathname()));
        $path = 'branding/' . $filename;

        SystemConfig::setValue('banner_path', $path);

        return back()->with('success', 'Background Login berhasil diunggah.');
    }

    public function uploadFavicon(Request $request)
    {
        $request->validate([
            'favicon' => 'required|image|mimes:png,jpg,jpeg,ico,svg|max:2048',
        ]);

        $oldPath = SystemConfig::getValue('favicon_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('favicon');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/' . $filename, file_get_contents($file->getPathname()));
        $path = 'branding/' . $filename;

        SystemConfig::setValue('favicon_path', $path);

        return back()->with('success', 'Favicon berhasil diunggah.');
    }

    public function uploadSound(Request $request)
    {
        $request->validate([
            'sound' => 'required|file|mimes:mp3,wav|max:5120',
        ]);

        $oldPath = SystemConfig::getValue('notification_sound_path');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        $file = $request->file('sound');
        $filename = $file->hashName();
        Storage::disk('public')->put('branding/' . $filename, file_get_contents($file->getPathname()));
        $path = 'branding/' . $filename;

        SystemConfig::setValue('notification_sound_path', $path);

        return back()->with('success', 'Suara Notifikasi berhasil diunggah.');
    }
}
