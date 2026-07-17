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

        $stats = [
            'aktif' => Ticket::where('user_id', $userId)->whereNotIn('status', ['selesai', 'ditolak', 'batal'])->count(),
            'diproses' => Ticket::where('user_id', $userId)->where('status', 'on_proses')->count(),
            'selesai' => Ticket::where('user_id', $userId)->where('status', 'selesai')->count(),
            'ditolak' => Ticket::where('user_id', $userId)->where('status', 'ditolak')->count(),
        ];

        return inertia('User/Dashboard', [
            'recentTickets' => $recentTickets,
            'stats' => $stats,
        ]);
    }
}
