<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Csat;
use App\Models\Ticket;
use Illuminate\Http\Request;

class CsatApiController extends Controller
{
    /**
     * Submit a CSAT rating for a ticket.
     */
    public function store(Request $request, string $id)
    {
        $user = $request->user();

        $ticket = Ticket::with('subUnit')
            ->where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if ((int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        if (!in_array(strtolower($ticket->status), ['solve', 'selesai'])) {
            return response()->json([
                'message' => 'Rating hanya bisa diberikan untuk tiket yang sudah diselesaikan'
            ], 422);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'komentar' => 'nullable|string|max:1000',
        ]);

        $csat = Csat::updateOrCreate(
            ['ticket_id' => $ticket->id, 'user_id' => $user->id],
            [
                'rating' => $validated['rating'],
                'komentar' => $validated['komentar'] ?? null,
            ]
        );

        // Update ticket status to 'Selesai' after rating
        if (in_array(strtolower($ticket->status), ['solve', 'selesai'])) {
            if (strtolower($ticket->status) === 'solve' || $ticket->status !== 'Selesai') {
                $ticket->update(['status' => 'Selesai']);
            }
        }

        // Notify admins
        try {
            $notifiedAdmins = \App\Models\Admin::whereHas('units', function ($query) use ($ticket) {
                $query->where('units.id', $ticket->subUnit?->unit_id);
            })->orWhereHas('roles', function ($q) {
                $q->where('name', 'superadmin');
            })->get();

            if ($notifiedAdmins->isNotEmpty() && class_exists(\App\Notifications\TicketRatedAdminNotification::class)) {
                \Illuminate\Support\Facades\Notification::send($notifiedAdmins, new \App\Notifications\TicketRatedAdminNotification($ticket, $validated['rating'], $validated['komentar'] ?? ''));
            }
        } catch (\Exception $e) {
            // Silently ignore notification errors
        }

        return response()->json([
            'data' => [
                'id' => $csat->id,
                'ticketId' => $ticket->formatted_id ?? (string) $ticket->id,
                'rating' => $csat->rating,
                'comment' => $csat->komentar,
                'createdAt' => $csat->created_at->toIso8601String(),
            ],
            'message' => 'Terima kasih atas rating Anda!',
        ], 201);
    }

    /**
     * Get user's CSAT rating history.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $csats = Csat::where('user_id', $user->id)
            ->with(['ticket' => function ($q) {
                $q->select('id', 'status', 'sub_unit_id', 'form_data', 'created_at')
                    ->with('subUnit:id,nama_layanan');
            }])
            ->latest()
            ->paginate($request->get('per_page', 10));

        $formattedCsats = $csats->getCollection()->map(function ($csat) {
            return [
                'id' => (string) $csat->id,
                'ticketId' => $csat->ticket ? ($csat->ticket->formatted_id ?? (string) $csat->ticket->id) : '',
                'ticketTitle' => $csat->ticket ? $csat->ticket->judul : '',
                'category' => $csat->ticket && $csat->ticket->subUnit ? $csat->ticket->subUnit->nama_layanan : '',
                'score' => $csat->rating,
                'comment' => $csat->komentar,
                'createdAt' => $csat->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'data' => $formattedCsats,
            'meta' => [
                'current_page' => $csats->currentPage(),
                'last_page' => $csats->lastPage(),
                'per_page' => $csats->perPage(),
                'total' => $csats->total(),
            ],
        ]);
    }
}
