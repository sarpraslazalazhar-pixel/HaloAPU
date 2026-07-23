<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use App\Models\RoomVehicleBooking;
use App\Models\SystemConfig;
use App\Models\Unit;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

class TvDashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = Carbon::today();

        // Statistik Hari Ini
        $stats = [
            'total_hari_ini' => Ticket::whereDate('created_at', $today)->count(),
            'menunggu' => Ticket::where('status', 'Menunggu')->count(),
            'diproses' => Ticket::whereIn('status', ['Diproses', 'Eskalasi', 'Menunggu Konfirmasi'])->count(),
            'selesai' => Ticket::whereDate('updated_at', $today)->where('status', 'Selesai')->count(),
        ];

        // Tiket Terbaru (Live Feed)
        $recentTickets = Ticket::with(['subUnit:id,nama_layanan', 'user:id,name', 'unit:id,nama_unit'])
            ->orderBy('created_at', 'desc')
            ->take(15)
            ->get();

        // Jadwal Booking Mendatang (dari hari ini ke depan)
        $upcomingBookings = RoomVehicleBooking::with(['ticket:id,ticket_number,user_id', 'ticket.user:id,name'])
            ->whereDate('tanggal_mulai', '>=', $today)
            ->whereIn('status', ['open', 'on_proses'])
            ->orderBy('tanggal_mulai', 'asc')
            ->take(10)
            ->get();

        // ── Daily Chart (7 Hari Terakhir) ──
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

        $dailyChartData = $dates->map(function ($dateStr) use ($dailyRaw, $unitNames) {
            $row = ['date' => $dateStr];
            foreach ($unitNames as $id => $name) {
                $row[$name] = $dailyRaw->firstWhere(fn($r) => $r->date === $dateStr && $r->unit_id === $id)?->total ?? 0;
            }
            return $row;
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
