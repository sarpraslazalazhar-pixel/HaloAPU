<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index()
    {
        $recentTickets = Ticket::where('user_id', auth()->id())
            ->with(['subUnit:id,nama_layanan'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return inertia('User/Dashboard', [
            'recentTickets' => $recentTickets
        ]);
    }
}
