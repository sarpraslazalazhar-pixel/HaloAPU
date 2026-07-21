<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        
        $recentTickets = Ticket::where('user_id', $userId)
            ->with(['subUnit:id,nama_layanan'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        $ticketCounts = Ticket::where('user_id', $userId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($item, $key) => [strtolower($key) => $item]);

        $stats = [
            'aktif' => collect($ticketCounts)->except(['solve', 'selesai', 'reject', 'dibatalkan'])->sum(),
            'diproses' => $ticketCounts['on_proses'] ?? 0,
            'selesai' => ($ticketCounts['solve'] ?? 0) + ($ticketCounts['selesai'] ?? 0),
            'ditolak' => $ticketCounts['reject'] ?? 0,
        ];

        return inertia('User/Dashboard', [
            'recentTickets' => $recentTickets,
            'stats' => $stats,
        ]);
    }
}
