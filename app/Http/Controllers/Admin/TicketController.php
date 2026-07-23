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
        } else {
            $query->whereIn('status', ['open', 'on_proses', 'pending', 'waiting_approval', 'need_revision']);
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
            'logs.attachments',
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
            'on_proses' => ['solve', 'pending', 'reject'],
            'pending' => ['on_proses'],
            'need_revision' => ['solve', 'pending', 'reject'],
        ];

        $request->validate([
            'status' => 'required|string',
            'catatan' => 'required|string|max:1000',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $newStatus = $request->status;
        $oldStatus = $ticket->status;

        if (!isset($validTransitions[$oldStatus]) || !in_array($newStatus, $validTransitions[$oldStatus])) {
            return redirect()->back()->with('error', 'Transisi status tidak valid.');
        }



        $sla = $ticket->slaTracking;

        if ($oldStatus === 'open' && $newStatus === 'on_proses') {
            if ($sla && !$sla->responded_at) {
                $respondedAt = now();
                $sla->update([
                    'responded_at' => $respondedAt,
                    'is_response_breached' => $sla->sla_response_deadline && $respondedAt->gt($sla->sla_response_deadline),
                ]);
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
                $respondedAt = now();
                $sla->update([
                    'responded_at' => $respondedAt,
                    'is_response_breached' => $sla->sla_response_deadline && $respondedAt->gt($sla->sla_response_deadline),
                ]);
            }
        }

        if ($newStatus === 'on_proses' && $ticket->booking) {
            if ($sla && !$sla->resolved_at) {
                $resolvedAt = now();
                $sla->update([
                    'resolved_at' => $resolvedAt,
                    'is_resolution_breached' => $sla->sla_resolution_deadline && $resolvedAt->gt($sla->sla_resolution_deadline),
                ]);
            }
        }

        if ($newStatus === 'solve') {
            if ($sla && !$sla->resolved_at) {
                $resolvedAt = now();
                $sla->update([
                    'resolved_at' => $resolvedAt,
                    'is_resolution_breached' => $sla->sla_resolution_deadline && $resolvedAt->gt($sla->sla_resolution_deadline),
                ]);
            }
            // Reset is_result_accepted saat admin set solve (user perlu review lagi)
            $ticket->update(['is_result_accepted' => false]);
        }

        $ticket->update(['status' => $newStatus]);

        if ($ticket->booking) {
            $ticket->booking->update(['status' => $newStatus]);
        }

        $log = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => auth('admin')->id(),
            'aksi' => $newStatus,
            'catatan' => $request->catatan,
        ]);

        $generalFiles = $request->file('general_attachments');
        if (!empty($generalFiles) && is_array($generalFiles)) {
            foreach ($generalFiles as $file) {
                if (!$file || !is_a($file, \Illuminate\Http\UploadedFile::class) || !$file->isValid()) continue;

                $path = \Illuminate\Support\Facades\Storage::disk('public')->putFileAs(
                    "ticket-attachments/{$ticket->id}",
                    $file->getPathname(),
                    $file->hashName()
                );

                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'field_id' => null,
                    'ticket_log_id' => $log->id,
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'wajib' => false,
                ]);
            }
        }

        // Notifikasi WA (User)
        try {
            $ticket->load('user', 'subUnit');
            if ($ticket->user) {
                $ticket->user->notify(new TicketStatusUpdatedNotification($ticket, $request->catatan));
                $ticket->user->notify(new \App\Notifications\BrowserNotification(
                    "Status Tiket #{$ticket->ticket_number} Diperbarui",
                    "Tiket Anda kini berstatus: " . strtoupper($newStatus),
                    "/user/tiket/{$ticket->id}"
                ));
                
                if (!empty($request->catatan)) {
                    $senderName = auth('admin')->user()->name ?? auth('admin')->user()->username;
                    $url = route('tiket.show', $ticket->id);
                    $ticket->user->notify(new \App\Notifications\TicketCommentPushNotification($ticket, $senderName, $request->catatan, $url));
                }
            }
        } catch (\Exception $e) {
            \Log::error("Gagal mengirim notifikasi status update tiket #{$ticket->id}: " . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Status tiket berhasil diubah.');
    }

    public function updatePriority(Request $request, Ticket $ticket, SlaCalculator $slaCalculator)
    {
        $request->validate([
            'priority' => 'required|string|in:Rendah,Sedang,Tinggi,Urgen',
        ]);

        $oldPriority = $ticket->priority;
        $newPriority = $request->priority;

        if ($oldPriority !== $newPriority) {
            $ticket->update(['priority' => $newPriority]);

            // Recalculate SLA if ticket is still open/on_proses and tracking exists
            if ($ticket->slaTracking && !in_array($ticket->status, ['solve', 'reject'])) {
                $responseDeadline = $slaCalculator->calculateResponseDeadline($ticket);
                $resolutionDeadline = $slaCalculator->calculateResolutionDeadline($ticket);
                
                $ticket->slaTracking->update([
                    'sla_response_deadline' => $responseDeadline,
                    'sla_resolution_deadline' => $resolutionDeadline,
                ]);
            }

            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => auth('admin')->id(),
                'aksi' => 'update_priority',
                'catatan' => "Prioritas diubah dari " . ($oldPriority ?? 'Belum diset') . " menjadi $newPriority",
            ]);
        }

        return redirect()->back()->with('success', 'Prioritas tiket berhasil diatur.');
    }

    public function downloadAttachment(TicketAttachment $attachment)
    {
        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    public function viewAttachment(TicketAttachment $attachment)
    {
        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="' . $attachment->original_name . '"'
        ];

        return response()->file(Storage::disk('public')->path($attachment->file_path), $headers);
    }
}
