<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\FormField;
use App\Models\SystemConfig;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Notifications\BrowserNotification;
use App\Notifications\RevisionRequestedNotification;
use App\Notifications\TicketCommentPushNotification;
use App\Services\SparcIntegrationService;
use App\Services\TicketChatBridgeService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
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
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tickets.id', 'like', "%{$search}%")
                    ->orWhereHas('subUnit', fn ($s) => $s->where('nama_layanan', 'like', "%{$search}%"))
                    ->orWhereHas('unit', fn ($u) => $u->where('nama_unit', 'like', "%{$search}%"));
            });
        }

        $tickets = $query->orderByDesc('created_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('User/Tiket/Riwayat', [
            'tickets' => $tickets,
            'filters' => $request->only('status', 'date_from', 'date_to', 'search'),
            'statuses' => ['open', 'on_proses', 'pending', 'solve', 'reject', 'dibatalkan', 'waiting_approval', 'need_revision'],
        ]);
    }

    public function download(TicketAttachment $attachment)
    {
        $ticket = $attachment->ticket;
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }

        if (! Storage::disk('public')->exists($attachment->file_path)) {
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

        if (! Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        $headers = [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'inline; filename="'.$attachment->original_name.'"',
        ];

        return response()->file(Storage::disk('public')->path($attachment->file_path), $headers);
    }

    public function show(Ticket $ticket)
    {
        // Pastikan tiket milik user yang login
        if ((int) $ticket->user_id !== (int) auth()->id()) {
            abort(403, 'Kamu tidak memiliki akses ke tiket ini.');
        }

        $ticket->load([
            'unit',
            'subUnit',
            'orgDivisi',
            'orgUnit',
            'jabatan',
            'booking',
            'attachments.field',
            'attachments.log',
            'attachments.log.admin',
            'csat',
            'logs' => function ($q) {
                $q->orderBy('timestamp', 'desc');
            },
            'logs.admin',
            'logs.attachments',
        ]);

        // Ambil form fields untuk mapping label
        $formFields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get();

        $maxRevisions = (int) SystemConfig::getValue('max_revisions', 5);

        return Inertia::render('User/Tiket/Detail', [
            'ticket' => $ticket,
            'formFields' => $formFields,
            'maxRevisions' => $maxRevisions,
        ]);
    }

    public function cancel(Ticket $ticket)
    {
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }

        $isBooking = $ticket->booking !== null;
        if ($isBooking && $ticket->booking->tanggal_selesai && now()->gte($ticket->booking->tanggal_selesai)) {
            return redirect()->back()->with('error', 'Masa peminjaman telah berakhir, tidak dapat dibatalkan.');
        }

        $canCancelBooking = false;
        if ($isBooking) {
            // Bisa dibatalkan jika belum mulai atau belum lewat tanggal_selesai
            $canCancelBooking = now()->lt($ticket->booking->tanggal_mulai)
                && ! in_array($ticket->booking->status, ['dibatalkan', 'reject', 'selesai']);
        }

        if ($ticket->status !== 'open' && ! $canCancelBooking) {
            return redirect()->back()->with('error', 'Hanya tiket berstatus Open atau peminjaman yang belum dimulai yang bisa dibatalkan.');
        }

        $ticket->update(['status' => 'dibatalkan']);

        if ($ticket->booking) {
            $ticket->booking->update(['status' => 'dibatalkan']);
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'dibatalkan',
            'catatan' => 'Tiket/peminjaman dibatalkan oleh user '.auth()->user()->username,
        ]);

        // Notifikasi ke Admin unit terkait
        try {
            $ticket->load('subUnit.unit.admins');
            $admins = $ticket->subUnit?->unit?->admins ?? collect();
            foreach ($admins as $admin) {
                $admin->notify(new BrowserNotification(
                    'Peminjaman Dibatalkan',
                    'User '.(auth()->user()->name ?? auth()->user()->username)." membatalkan tiket/booking #{$ticket->formatted_id}",
                    "/admin/tiketing/{$ticket->id}"
                ));
            }
        } catch (\Exception $e) {
            \Log::error('Gagal kirim notif batal user: '.$e->getMessage());
        }

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.cancelled');

        return redirect()->route('tiket.riwayat')->with('success', 'Tiket dan jadwal peminjaman berhasil dibatalkan. Aset kini tersedia kembali.');
    }

    public function reply(Request $request, Ticket $ticket)
    {
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }

        if (in_array($ticket->status, ['solve', 'reject', 'dibatalkan'])) {
            return redirect()->back()->with('error', 'Tidak dapat membalas tiket yang sudah selesai atau dibatalkan.');
        }

        $request->validate([
            'catatan' => 'required|string|max:1000',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $log = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'balasan',
            'catatan' => $request->catatan,
            'timestamp' => now(),
        ]);

        $generalFiles = $request->file('general_attachments');
        if (! empty($generalFiles) && is_array($generalFiles)) {
            foreach ($generalFiles as $file) {
                if (! $file || ! is_a($file, UploadedFile::class) || ! $file->isValid()) {
                    continue;
                }

                $path = Storage::disk('public')->putFileAs(
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
        $log->load('attachments');
        TicketChatBridgeService::syncTicketReplyToChat($ticket, $log);

        $notifiedAdmins = Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->subUnit->unit_id);
        })->orWhereHas('roles', function ($q) {
            $q->whereIn('name', ['superadmin', 'Super Admin']);
        })->get();

        if ($notifiedAdmins->isNotEmpty()) {
            $senderName = auth()->user()->name ?? auth()->user()->username;
            $url = route('admin.tiket.show', $ticket->id);
            Notification::send($notifiedAdmins, new TicketCommentPushNotification($ticket, $senderName, $request->catatan, $url));
        }

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.replied');

        return redirect()->back()->with('success', 'Balasan berhasil dikirim.');
    }

    public function acceptResult(Ticket $ticket)
    {
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }

        if ($ticket->status !== 'solve') {
            return redirect()->back()->with('error', 'Tiket tidak dalam status selesai.');
        }

        if (! $ticket->subUnit->is_revision_enabled) {
            return redirect()->back()->with('error', 'Fitur revisi tidak diaktifkan untuk jenis layanan ini.');
        }

        $ticket->update(['is_result_accepted' => true]);

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'accepted',
            'catatan' => 'Hasil akhir diterima oleh user.',
        ]);

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.result_accepted');

        return redirect()->back()->with('success', 'Hasil telah kamu terima.');
    }

    public function requestRevision(Request $request, Ticket $ticket)
    {
        if ($ticket->user_id !== auth()->id()) {
            abort(403);
        }

        if ($ticket->status !== 'solve') {
            return redirect()->back()->with('error', 'Tiket tidak dalam status selesai.');
        }

        if (! $ticket->subUnit->is_revision_enabled) {
            return redirect()->back()->with('error', 'Fitur revisi tidak diaktifkan untuk jenis layanan ini.');
        }

        $maxRevisions = (int) SystemConfig::getValue('max_revisions', 5);

        if ($ticket->revision_count >= $maxRevisions) {
            return redirect()->back()->with('error', 'Kamu telah mencapai batas maksimal revisi ('.$maxRevisions.' kali).');
        }

        $request->validate([
            'catatan' => 'required|string|max:1000',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $ticket->update([
            'status' => 'need_revision',
            'revision_count' => $ticket->revision_count + 1,
        ]);

        $log = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'need_revision',
            'catatan' => $request->catatan,
        ]);

        $generalFiles = $request->file('general_attachments');
        if (! empty($generalFiles) && is_array($generalFiles)) {
            foreach ($generalFiles as $file) {
                if (! $file || ! is_a($file, UploadedFile::class) || ! $file->isValid()) {
                    continue;
                }

                $path = Storage::disk('public')->putFileAs(
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

        $notifiedAdmins = Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->subUnit->unit_id);
        })->get();

        if ($notifiedAdmins->isNotEmpty()) {
            Notification::send($notifiedAdmins, new RevisionRequestedNotification($ticket, $request->catatan));
        } else {
            Notification::send(new AnonymousNotifiable, new RevisionRequestedNotification($ticket, $request->catatan));
        }

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.revision_requested');

        return redirect()->back()->with('success', 'Permintaan revisi berhasil dikirim.');
    }

    public function kembalikanAlat(Request $request, Ticket $ticket)
    {
        if ((int) $ticket->user_id !== (int) auth()->id()) {
            abort(403);
        }

        // Pastikan sub unit wajib_kembali aktif
        if ($ticket->subUnit && ! $ticket->subUnit->wajib_kembali) {
            $ticket->subUnit->update(['wajib_kembali' => true]);
        }

        $statusNormalized = strtolower(trim((string) $ticket->status));
        if (! in_array($statusNormalized, ['solve', 'selesai'])) {
            return redirect()->back()->with('error', 'Status tiket harus selesai terlebih dahulu.');
        }

        $validated = $request->validate([
            'kondisi_kembali' => 'nullable|string|in:baik,rusak,tidak_lengkap',
            'catatan_kembali' => 'nullable|string|max:1000',
        ]);

        $kondisi = $validated['kondisi_kembali'] ?? 'baik';
        $catatan = ! empty($validated['catatan_kembali']) ? trim($validated['catatan_kembali']) : null;

        $now = now();
        $ticket->dikembalikan_at = $now;
        if (Schema::hasColumn('tickets', 'kondisi_kembali')) {
            $ticket->kondisi_kembali = $kondisi;
        }
        if (Schema::hasColumn('tickets', 'catatan_kembali')) {
            $ticket->catatan_kembali = $catatan;
        }
        $ticket->save();

        $kondisiLabel = match ($kondisi) {
            'rusak' => 'Rusak',
            'tidak_lengkap' => 'Tidak Lengkap (Aksesoris Kurang)',
            default => 'Baik (Normal)',
        };

        $logCatatan = 'Alat dikembalikan [Kondisi: '.$kondisiLabel.'], ditandai oleh user '.auth()->user()->username;
        if ($catatan) {
            $logCatatan .= ' — Catatan: '.$catatan;
        }

        try {
            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => null,
                'aksi' => 'alat_dikembalikan',
                'catatan' => $logCatatan,
                'timestamp' => $now,
            ]);
        } catch (\Exception $e) {
            \Log::error('Log error: '.$e->getMessage());
        }

        return redirect()->back()->with('success', 'Alat berhasil ditandai sudah dikembalikan.');
    }
}
