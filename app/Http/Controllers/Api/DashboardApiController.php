<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use Illuminate\Http\Request;

class DashboardApiController extends Controller
{
    /**
     * Get dashboard data: ticket stats + recent tickets.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Ticket::query();

        if ($user instanceof \App\Models\Admin) {
            $unitIds = $user->units()->pluck('units.id')->toArray();
            $subUnitIds = $user->subUnits()->pluck('sub_units.id')->toArray();

            // If not super_admin, filter by assigned units/subunits
            if (!$user->hasRole('super_admin')) {
                $query->where(function ($q) use ($unitIds, $subUnitIds) {
                    if (!empty($unitIds)) {
                        $q->orWhereIn('unit_id', $unitIds);
                    }
                    if (!empty($subUnitIds)) {
                        $q->orWhereIn('sub_unit_id', $subUnitIds);
                    }
                });
            }
        } else {
            $query->where('user_id', $user->id);
        }

        // Ticket counts by status
        $ticketCounts = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($item, $key) => [strtolower($key) => $item]);

        $stats = [
            'menunggu' => $ticketCounts['open'] ?? 0,
            'diproses' => ($ticketCounts['on_proses'] ?? 0) + ($ticketCounts['assigned'] ?? 0),
            'selesai' => ($ticketCounts['solve'] ?? 0) + ($ticketCounts['selesai'] ?? 0),
            'ditolak' => ($ticketCounts['reject'] ?? 0) + ($ticketCounts['dibatalkan'] ?? 0),
            'aktif' => collect($ticketCounts)->except(['solve', 'selesai', 'reject', 'dibatalkan'])->sum(),
        ];

        // Recent tickets
        $recentTickets = (clone $query)
            ->with(['subUnit:id,nama_layanan'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($ticket) {
                $flutterStatus = match(strtolower($ticket->status)) {
                    'open' => 'open',
                    'assigned' => 'assigned',
                    'on_proses' => 'processing',
                    'solve', 'selesai' => 'solved',
                    'reject', 'dibatalkan' => 'rejected',
                    default => 'open'
                };

                return [
                    'id' => $ticket->formatted_id ?? (string) $ticket->id,
                    'title' => $ticket->judul,
                    'category' => $ticket->subUnit ? $ticket->subUnit->nama_layanan : 'General',
                    'status' => $flutterStatus,
                    'createdAt' => $ticket->created_at->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => [
                'stats' => $stats,
                'recentTickets' => $recentTickets,
            ],
        ]);
    }
}
