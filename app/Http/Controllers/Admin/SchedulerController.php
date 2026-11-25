<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SchedulerController extends Controller
{
    /**
     * Jalankan command SLA check secara manual.
     */
    public function runSlaCheck()
    {
        try {
            Artisan::call('sla:check');
            $output = trim(Artisan::output());
            Log::info('Manual SLA Check executed', ['output' => $output]);
            return back()->with('success', 'SLA Check berhasil dijalankan. ' . $output);
        } catch (\Exception $e) {
            Log::error('Manual SLA Check failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menjalankan SLA Check: ' . $e->getMessage());
        }
    }

    /**
     * Jalankan command reminder booking secara manual.
     */
    public function runBookingReminder()
    {
        try {
            Artisan::call('reminder:booking');
            $output = trim(Artisan::output());
            Log::info('Manual Booking Reminder executed', ['output' => $output]);
            return back()->with('success', 'Booking Reminder berhasil dijalankan. ' . $output);
        } catch (\Exception $e) {
            Log::error('Manual Booking Reminder failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menjalankan Booking Reminder: ' . $e->getMessage());
        }
    }

    /**
     * Jalankan command reminder pending ticket secara manual.
     */
    public function runPendingReminder()
    {
        try {
            Artisan::call('reminder:pending');
            $output = trim(Artisan::output());
            Log::info('Manual Pending Reminder executed', ['output' => $output]);
            return back()->with('success', 'Pending Reminder berhasil dijalankan. ' . $output);
        } catch (\Exception $e) {
            Log::error('Manual Pending Reminder failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menjalankan Pending Reminder: ' . $e->getMessage());
        }
    }

    /**
     * Jalankan command reminder CSAT secara manual.
     */
    public function runCsatReminder()
    {
        try {
            Artisan::call('reminder:csat');
            $output = trim(Artisan::output());
            Log::info('Manual CSAT Reminder executed', ['output' => $output]);
            return back()->with('success', 'CSAT Reminder berhasil dijalankan. ' . $output);
        } catch (\Exception $e) {
            Log::error('Manual CSAT Reminder failed', ['error' => $e->getMessage()]);
            return back()->with('error', 'Gagal menjalankan CSAT Reminder: ' . $e->getMessage());
        }
    }

    /**
     * Jalankan command briefing operasional AI secara manual.
     */
    public function runAiBriefing(Request $request)
    {
        $validated = $request->validate([
            'period' => 'nullable|in:daily,weekly',
        ]);

        $period = $validated['period'] ?? 'daily';

        try {
            Artisan::call('ai:briefing', [
                '--period' => $period,
                '--force' => true,
            ]);
            $output = trim(Artisan::output());
            Log::info('Manual AI Briefing executed', ['period' => $period, 'output' => $output]);

            return back()->with('success', "Briefing AI ({$period}) berhasil dijalankan. " . $output);
        } catch (\Exception $e) {
            Log::error('Manual AI Briefing failed', ['period' => $period, 'error' => $e->getMessage()]);

            return back()->with('error', 'Gagal menjalankan Briefing AI: ' . $e->getMessage());
        }
    }

    /**
     * Jalankan semua scheduler sekaligus.
     */
    public function runAll()
    {
        $results = [];
        $commands = [
            'sla:check' => 'SLA Check',
            'reminder:booking' => 'Booking Reminder',
            'reminder:pending' => 'Pending Reminder',
            'reminder:csat' => 'CSAT Reminder',
        ];

        foreach ($commands as $command => $label) {
            try {
                Artisan::call($command);
                $results[] = "✅ {$label}: " . trim(Artisan::output());
            } catch (\Exception $e) {
                $results[] = "❌ {$label}: " . $e->getMessage();
            }
        }

        Log::info('Manual Run All Schedulers', ['results' => $results]);
        return back()->with('success', implode(' | ', $results));
    }
}
