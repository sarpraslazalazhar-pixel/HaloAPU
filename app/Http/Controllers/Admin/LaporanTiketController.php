<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Ticket;
use Carbon\Carbon;

class LaporanTiketController extends Controller
{
    public function index(Request $request)
    {
        $year = $request->get('year', date('Y'));
        
        // Data for charts
        $ticketsByStatus = Ticket::selectRaw('status, count(*) as count')
            ->whereYear('created_at', $year)
            ->groupBy('status')
            ->get();

        $ticketsByMonth = Ticket::selectRaw('MONTH(created_at) as month, count(*) as count')
            ->whereYear('created_at', $year)
            ->groupBy('month')
            ->get();

        $ticketsByLayanan = Ticket::selectRaw('sub_units.nama_layanan as layanan, count(tickets.id) as count')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->whereYear('tickets.created_at', $year)
            ->groupBy('sub_units.id', 'sub_units.nama_layanan')
            ->get();

        $tickets = Ticket::with(['user', 'subUnit.unit', 'slaTracking'])
            ->whereYear('created_at', $year)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/Laporan/Tiket', [
            'year' => $year,
            'ticketsByStatus' => $ticketsByStatus,
            'ticketsByMonth' => $ticketsByMonth,
            'ticketsByLayanan' => $ticketsByLayanan,
            'tickets' => $tickets,
        ]);
    }
}
