<?php

namespace App\Http\Controllers;

use App\Models\Csat;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CsatController extends Controller
{
    public function store(Request $request, Ticket $ticket)
    {
        if ($ticket->user_id !== $request->user()->id) {
            abort(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        if (!in_array(strtolower($ticket->status), ['solve', 'selesai'])) {
            return back()->withErrors([
                'rating' => 'Rating hanya bisa diberikan untuk tiket yang sudah diselesaikan.',
            ]);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'komentar' => 'nullable|string|max:1000',
        ]);

        Csat::updateOrCreate(
            ['ticket_id' => $ticket->id, 'user_id' => $request->user()->id],
            [
                'rating' => $validated['rating'],
                'komentar' => $validated['komentar'],
            ]
        );

        if (in_array(strtolower($ticket->status), ['solve', 'selesai'])) {
            if (strtolower($ticket->status) === 'solve' || $ticket->status !== 'Selesai') {
                $ticket->update(['status' => 'Selesai']);
            }
        }

        $notifiedAdmins = \App\Models\Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->subUnit->unit_id);
        })->get();

        if ($notifiedAdmins->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send($notifiedAdmins, new \App\Notifications\TicketRatedAdminNotification($ticket, $validated['rating'], $validated['komentar'] ?? ''));
        }

        return back()->with('success', 'Terima kasih atas rating Anda!');
    }

    public function riwayat(Request $request)
    {
        $csats = Csat::where('user_id', $request->user()->id)
            ->with(['ticket' => function ($q) {
                $q->select('id', 'status', 'sub_unit_id', 'created_at')
                    ->with('subUnit:id,nama_layanan');
            }])
            ->latest()
            ->paginate(10);

        return Inertia::render('User/Csat/Riwayat', [
            'csats' => $csats,
        ]);
    }
}
