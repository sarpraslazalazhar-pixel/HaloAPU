<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Csat;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CsatController extends Controller
{
    public function index(Request $request)
    {
        $query = Csat::with([
            'ticket' => fn ($q) => $q->with('subUnit.unit'),
            'user:id,username,email',
        ]);

        if ($unitId = $request->get('unit_id')) {
            $query->whereHas('ticket.subUnit.unit', fn ($q) => $q->where('id', $unitId));
        }

        if ($subUnitId = $request->get('sub_unit_id')) {
            $query->whereHas('ticket', fn ($q) => $q->where('sub_unit_id', $subUnitId));
        }

        if ($bulan = $request->get('bulan')) {
            $query->whereYear('created_at', substr($bulan, 0, 4))
                  ->whereMonth('created_at', substr($bulan, 5, 2));
        }

        if ($ratingMin = $request->get('rating_min')) {
            $query->where('rating', '>=', $ratingMin);
        }
        if ($ratingMax = $request->get('rating_max')) {
            $query->where('rating', '<=', $ratingMax);
        }

        $csats = $query->latest()->paginate(15);

        $statsQuery = Csat::query();
        if ($unitId) {
            $statsQuery->whereHas('ticket.subUnit.unit', fn ($q) => $q->where('id', $unitId));
        }

        $avgRating = round($statsQuery->avg('rating'), 2);
        $totalRating = $statsQuery->count();
        $ratingDistribution = $statsQuery->select('rating', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('rating')
            ->orderBy('rating')
            ->get();

        $csatPerUnit = Csat::join('tickets', 'csats.ticket_id', '=', 'tickets.id')
            ->join('sub_units', 'tickets.sub_unit_id', '=', 'sub_units.id')
            ->join('units', 'sub_units.unit_id', '=', 'units.id')
            ->select(
                'units.nama_unit as unit_nama',
                DB::raw('ROUND(AVG(csats.rating), 2) as rata_rata'),
                DB::raw('COUNT(*) as total'),
            )
            ->groupBy('units.id', 'units.nama_unit')
            ->get();

        return Inertia::render('Admin/Csat/Index', [
            'csats' => $csats,
            'stats' => [
                'avgRating' => $avgRating,
                'totalRating' => $totalRating,
                'ratingDistribution' => $ratingDistribution,
            ],
            'csatPerUnit' => $csatPerUnit,
            'filters' => $request->only(['unit_id', 'sub_unit_id', 'bulan', 'rating_min', 'rating_max']),
            'units' => \App\Models\Unit::select('id', 'nama_unit')->get(),
            'subUnits' => \App\Models\SubUnit::select('id', 'unit_id', 'nama_layanan')->get(),
        ]);
    }
}
