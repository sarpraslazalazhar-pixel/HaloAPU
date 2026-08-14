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
            if (!$user->hasRole(['superadmin', 'super_admin', 'Super Admin'])) {
                $query->where(function ($q) use ($unitIds, $subUnitIds) {
                    if (!empty($unitIds)) {
                        $q->orWhereIn('unit_id', $unitIds);
                    }
                    if (!empty($subUnitIds)) {
                        $q->orWhereIn('sub_unit_id', $subUnitIds);
                    }
                });
            }
        } elseif ($user) {
            $query->where('user_id', $user->id);
        }

        // Ticket counts by status
        $ticketCounts = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->mapWithKeys(fn ($item, $key) => [strtolower($key) => $item]);

        $stats = [
            'menunggu' => (int) ($ticketCounts['open'] ?? 0),
            'diproses' => (int) (($ticketCounts['on_proses'] ?? 0) + ($ticketCounts['assigned'] ?? 0)),
            'selesai' => (int) (($ticketCounts['solve'] ?? 0) + ($ticketCounts['selesai'] ?? 0)),
            'ditolak' => (int) (($ticketCounts['reject'] ?? 0) + ($ticketCounts['dibatalkan'] ?? 0)),
            'aktif' => (int) collect($ticketCounts)->except(['solve', 'selesai', 'reject', 'dibatalkan'])->sum(),
        ];

        // Recent tickets
        $recentTickets = (clone $query)
            ->with(['subUnit:id,nama_layanan', 'user:id,name,username'])
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($ticket) {
                $flutterStatus = match(strtolower($ticket->status)) {
                    'open' => 'open',
                    'assigned', 'on_proses', 'processing' => 'on_proses',
                    'solve', 'selesai', 'solved' => 'solve',
                    'reject', 'rejected' => 'reject',
                    'dibatalkan', 'cancelled' => 'dibatalkan',
                    'need_revision' => 'need_revision',
                    default => 'open'
                };

                return [
                    'id' => $ticket->formatted_id ?? (string) $ticket->id,
                    'title' => $ticket->judul ?? $ticket->title ?? 'Tiket Layanan',
                    'description' => $ticket->deskripsi ?? $ticket->description ?? '',
                    'requesterName' => $ticket->user ? ($ticket->user->name ?? $ticket->user->username) : 'Pengguna',
                    'category' => $ticket->subUnit ? $ticket->subUnit->nama_layanan : 'General',
                    'status' => $flutterStatus,
                    'createdAt' => $ticket->created_at ? $ticket->created_at->toIso8601String() : now()->toIso8601String(),
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
