<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class TicketHistoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Ticket::where('user_id', auth()->id())
            ->with(['unit', 'subUnit']);

        // Filter status
        if ($request->has('status') && $request->status) {
            if (is_array($request->status)) {
                $query->whereIn('status', $request->status);
            } else {
                $query->where('status', $request->status);
            }
        }

        // Filter rentang tanggal
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $tickets = $query->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('User/Tiket/Riwayat', [
            'tickets' => $tickets,
            'filters' => $request->only('status', 'date_from', 'date_to'),
            'statuses' => ['open', 'on_proses', 'pending', 'solve', 'reject', 'dibatalkan'],
        ]);
    }

    public function download(TicketAttachment $attachment)
    {
        $ticket = $attachment->ticket;
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    public function viewAttachment(TicketAttachment $attachment)
    {
        $ticket = $attachment->ticket;
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="' . $attachment->original_name . '"'
        ];

        return response()->file(Storage::disk('public')->path($attachment->file_path), $headers);
    }

    public function show(Ticket $ticket)
    {
        // Pastikan tiket milik user yang login
        if ((int)$ticket->user_id !== (int)auth()->id()) {
            abort(403, 'Anda tidak memiliki akses ke tiket ini.');
        }

        $ticket->load([
            'unit',
            'subUnit',
            'orgDivisi',
            'orgUnit',
            'jabatan',
            'attachments',
            'csat',
            'logs' => function ($q) {
                $q->orderBy('timestamp', 'desc');
            },
            'logs.admin',
        ]);

        // Ambil form fields untuk mapping label
        $formFields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get();

        return Inertia::render('User/Tiket/Detail', [
            'ticket' => $ticket,
            'formFields' => $formFields,
        ]);
    }

    public function cancel(Ticket $ticket)
    {
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }
        if ($ticket->status !== 'open') {
            return redirect()->back()->with('error', 'Hanya tiket dengan status Open yang bisa dibatalkan.');
        }

        $ticket->update(['status' => 'dibatalkan']);

        if ($ticket->booking) {
            $ticket->booking->update(['status' => 'dibatalkan']);
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'dibatalkan',
            'catatan' => 'Tiket dibatalkan oleh ' . auth()->user()->username,
        ]);

        return redirect()->route('tiket.riwayat')->with('success', 'Tiket berhasil dibatalkan.');
    }
}
