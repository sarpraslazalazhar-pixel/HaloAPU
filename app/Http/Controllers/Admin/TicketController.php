<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FormField;
use App\Models\OrgDivisi;
use App\Models\OrgUnit;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\Unit;
use App\Services\SlaCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Notifications\TicketStatusUpdatedNotification;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $query = Ticket::with(['user.divisi', 'unit', 'subUnit', 'slaTracking']);

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }
        if ($request->filled('sub_unit_id')) {
            $query->where('sub_unit_id', $request->sub_unit_id);
        }
        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : [$request->status];
            $query->whereIn('status', $statuses);
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }
        if ($request->filled('divisi_id')) {
            $query->where('divisi_id', $request->divisi_id);
        }
        if ($request->filled('org_unit_id')) {
            $query->where('org_unit_id', $request->org_unit_id);
        }

        $tickets = $query->latest()->paginate(10)->withQueryString();

        return Inertia::render('Admin/Tiketing/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['unit_id', 'sub_unit_id', 'status', 'date_from', 'date_to', 'divisi_id', 'org_unit_id']),
            'units' => Unit::where('aktif', true)->orderBy('nama_unit')->get(),
            'divisiList' => OrgDivisi::orderBy('nama_divisi')->get(),
            'orgUnitList' => OrgUnit::orderBy('nama_unit_organisasi')->get(),
        ]);
    }

    public function show(Ticket $ticket)
    {
        $ticket->load([
            'user', 'user.divisi', 'user.orgUnit', 'user.jabatan',
            'unit', 'subUnit', 'orgDivisi', 'orgUnit', 'jabatan',
            'attachments.field', 'slaTracking',
            'logs' => fn($q) => $q->latest('timestamp'),
            'logs.admin',
        ]);

        $formFields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get();

        return Inertia::render('Admin/Tiketing/Detail', [
            'ticket' => $ticket,
            'formFields' => $formFields,
        ]);
    }

    public function updateStatus(Request $request, Ticket $ticket, SlaCalculator $slaCalculator)
    {
        $validTransitions = [
            'open' => ['on_proses', 'reject', 'pending'],
            'on_proses' => ['solve', 'pending'],
            'pending' => ['on_proses'],
        ];

        $request->validate([
            'status' => 'required|string',
            'catatan' => 'required|string|max:1000',
        ]);

        $newStatus = $request->status;
        $oldStatus = $ticket->status;

        if (!isset($validTransitions[$oldStatus]) || !in_array($newStatus, $validTransitions[$oldStatus])) {
            return redirect()->back()->with('error', 'Transisi status tidak valid.');
        }

        $sla = $ticket->slaTracking;

        if ($oldStatus === 'open' && $newStatus === 'on_proses') {
            if ($sla && !$sla->responded_at) {
                $sla->update(['responded_at' => now()]);
            }
        }

        if ($newStatus === 'pending' && $oldStatus !== 'pending') {
            if ($sla) {
                $slaCalculator->pauseSla($sla);
            }
        }

        if ($oldStatus === 'pending' && $newStatus === 'on_proses') {
            if ($sla) {
                $slaCalculator->resumeSla($sla);
            }
            if ($sla && !$sla->responded_at) {
                $sla->update(['responded_at' => now()]);
            }
        }

        if ($newStatus === 'solve') {
            if ($sla && !$sla->resolved_at) {
                $sla->update(['resolved_at' => now()]);
            }
        }

        $ticket->update(['status' => $newStatus]);

        if ($ticket->booking) {
            $ticket->booking->update(['status' => $newStatus]);
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => auth('admin')->id(),
            'aksi' => $newStatus,
            'catatan' => $request->catatan,
        ]);

        // Notifikasi WA (User)
        $ticket->load('user', 'subUnit');
        $ticket->user->notify(new TicketStatusUpdatedNotification($ticket, $request->catatan));

        return redirect()->back()->with('success', 'Status tiket berhasil diubah.');
    }

    public function downloadAttachment(TicketAttachment $attachment)
    {
        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }
}
