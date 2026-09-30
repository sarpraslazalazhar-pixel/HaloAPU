<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\RoomVehicleBooking;
use App\Models\SystemConfig;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Carbon\Carbon;

class TvDashboardController extends Controller
{
    public function index(Request $request)
    {
        // Require valid API token (or other auth mechanism) to view TV Dashboard
        $token = $request->query('token');
        $validToken = \App\Models\SystemConfig::getValue('tv_dashboard_token', 'HaloAPU-TV-Token-Secret'); // Use config, fallback to default secret

        if (!$token || $token !== $validToken) {
            return abort(403, 'Akses TV Dashboard Ditolak. Token tidak valid.');
        }

        $today = Carbon::today();
        $todayStr = $today->toDateString();

        // Statistik Hari Ini (Cached 15 Detik)
        $stats = Cache::remember("tv_dashboard_stats_{$todayStr}", 15, function () use ($todayStr) {
            $statRow = Ticket::selectRaw("
                SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as total_hari_ini,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as menunggu,
                SUM(CASE WHEN status = 'on_proses' THEN 1 ELSE 0 END) as diproses,
                SUM(CASE WHEN DATE(updated_at) = ? AND status IN ('solve', 'close') THEN 1 ELSE 0 END) as selesai
            ", [$todayStr, $todayStr])->first();

            return [
                'total_hari_ini' => (int) ($statRow->total_hari_ini ?? 0),
                'menunggu' => (int) ($statRow->menunggu ?? 0),
                'diproses' => (int) ($statRow->diproses ?? 0),
                'selesai' => (int) ($statRow->selesai ?? 0),
            ];
        });

        // Tiket Terbaru (Live Feed)
        $recentTickets = Ticket::with(['subUnit:id,nama_layanan', 'user:id,name', 'unit:id,nama_unit'])
            ->orderBy('created_at', 'desc')
            ->take(15)
            ->get();

        // Jadwal Booking Mendatang / Aktif (selesai dalam 1 jam terakhir atau di masa depan)
        $oneHourAgo = Carbon::now()->subHour();
        $upcomingBookings = RoomVehicleBooking::with(['ticket:id,user_id', 'ticket.user:id,name'])
            ->where('tanggal_selesai', '>=', $oneHourAgo)
            ->whereIn('status', ['open', 'on_proses'])
            ->orderBy('tanggal_mulai', 'asc')
            ->take(10)
            ->get();

        // ── Daily Chart (7 Hari Terakhir) — Cached 60 Detik ──
        $dailyChartData = Cache::remember('tv_dashboard_daily_chart', 60, function () {
            $startDate = now()->subDays(6)->startOfDay();
            $dailyRaw = Ticket::selectRaw('DATE(created_at) as date, unit_id, COUNT(*) as total')
                ->where('created_at', '>=', $startDate)
                ->groupBy('date', 'unit_id')
                ->get();

            $units = Unit::where('aktif', true)->orderBy('nama_unit')->get();
            $unitNames = $units->pluck('nama_unit', 'id');

            $dates = collect();
            for ($i = 6; $i >= 0; $i--) {
                $dates->push(now()->subDays($i)->format('Y-m-d'));
            }

            // Optimasi O(1) hash map: [$date][$unit_id] => total
            $dailyLookup = [];
            foreach ($dailyRaw as $r) {
                $dailyLookup[$r->date][$r->unit_id] = (int) $r->total;
            }

            return $dates->map(function ($dateStr) use ($dailyLookup, $unitNames) {
                $row = ['date' => $dateStr];
                foreach ($unitNames as $id => $name) {
                    $row[$name] = $dailyLookup[$dateStr][$id] ?? 0;
                }
                return $row;
            })->toArray();
        });

        return Inertia::render('Tv/Index', [
            'stats' => $stats,
            'recentTickets' => $recentTickets,
            'upcomingBookings' => $upcomingBookings,
            'dailyChartData' => $dailyChartData,
            'units' => $units,
            'notificationSound' => SystemConfig::getValue('notification_sound_path', null),
            'logoPath' => SystemConfig::getValue('logo_path', null),
        ]);
    }
}
