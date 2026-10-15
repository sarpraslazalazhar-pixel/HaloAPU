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
use App\Notifications\TicketCreatedAdminNotification;
use App\Models\Admin;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\TicketSlaTracking;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $query = Ticket::with(['user.divisi', 'createdByAdmin', 'unit', 'subUnit', 'slaTracking', 'assignedAdmin']);

        $admin = auth('admin')->user();
        $tab = $request->input('tab') === 'saya_ajukan' ? 'saya_ajukan' : 'masuk';
        if ($tab === 'saya_ajukan') {
            $query->submittedBy($admin);
        } else {
            $query->incomingFor($admin);
        }

        // Tiket selesai (solve/reject/dibatalkan) tidak tampil di daftar Tiketing utama, melainkan di Laporan Tiket
        $activeStatuses = ['open', 'on_proses', 'pending', 'need_revision'];

        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->unit_id);
        }
        if ($request->filled('sub_unit_id')) {
            $query->where('sub_unit_id', $request->sub_unit_id);
        }
        if ($request->filled('status')) {
            $statuses = is_array($request->status) ? $request->status : [$request->status];
            $query->whereIn('status', $statuses);
        } elseif ($tab === 'masuk') {
            $query->whereIn('status', $activeStatuses);
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
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('tickets.id', 'like', "%{$search}%")
                  ->orWhereHas('user', fn ($u) => $u->where('username', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                  ->orWhereHas('subUnit', fn ($s) => $s->where('nama_layanan', 'like', "%{$search}%"));
            });
        }

        $tickets = $query->latest()->paginate(10)->withQueryString();

        $units = Unit::aktif()->select('id', 'nama_unit')->orderBy('nama_unit')->get();
        $divisiList = OrgDivisi::select('id', 'nama_divisi')->orderBy('nama_divisi')->get();
        $orgUnitList = OrgUnit::select('id', 'nama_unit_organisasi')->orderBy('nama_unit_organisasi')->get();

        $activeCount = fn ($scope) => Ticket::query()->$scope($admin)->whereIn('status', $activeStatuses)->count();

        return Inertia::render('Admin/Tiketing/Index', [
            'tickets' => $tickets,
            'filters' => $request->only(['unit_id', 'sub_unit_id', 'status', 'date_from', 'date_to', 'divisi_id', 'org_unit_id', 'search']),
            'units' => $units,
            'divisiList' => $divisiList,
            'orgUnitList' => $orgUnitList,
            'currentTab' => $tab,
            'tabCounts' => [
                'masuk' => $activeCount('incomingFor'),
                'saya_ajukan' => $activeCount('submittedBy'),
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Tiketing/Create', [
            'unitList' => Unit::aktif()->orderBy('nama_unit')->get(),
        ]);
    }

    public function store(Request $request, SlaCalculator $slaCalculator)
    {
        if (is_string($request->form_data)) {
            $request->merge(['form_data' => json_decode($request->form_data, true) ?? []]);
        }

        $request->validate([
            'unit_id' => 'required|exists:units,id',
            'sub_unit_id' => 'required|exists:sub_units,id',
            'form_data' => 'required|array',
            'attachments' => 'nullable|array',
            'attachments.*' => 'nullable|array|max:3',
            'attachments.*.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $formFields = FormField::where('sub_unit_id', $request->sub_unit_id)->get();
        foreach ($formFields as $field) {
            if (!$field->wajib || $field->isUpload() || $field->tipe_field === 'info_peraturan') continue;
            if ($field->parent_field_id) {
                $parentValue = $request->form_data[(string) $field->parent_field_id] ?? null;
                $normalize = fn(string $s): string => rtrim(strtolower(trim($s)), ': ');
                $triggerNorm = $normalize((string) $field->trigger_value);
                $isMatched = is_array($parentValue)
                    ? collect($parentValue)->contains(fn($v) => $normalize((string) $v) === $triggerNorm)
                    : $normalize((string) $parentValue) === $triggerNorm;
                if (!$isMatched) continue;
            }
            $fieldKey = (string) $field->id;
            if (empty($request->form_data[$fieldKey])) {
                return back()->withErrors([
                    'form_data.' . $fieldKey => "Field \"{$field->label}\" wajib diisi.",
                ]);
            }
        }

        $admin = auth('admin')->user();

        $ticket = DB::transaction(function () use ($request, $formFields, $slaCalculator, $admin) {
            $initialStatus = $slaCalculator->isWithinWorkingHours(now()) ? 'open' : 'pending';

            $ticket = Ticket::create([
                'user_id' => null,
                'created_by_admin_id' => $admin->id,
                'unit_id' => $request->unit_id,
                'sub_unit_id' => $request->sub_unit_id,
                'form_data' => $request->form_data,
                'status' => $initialStatus,
            ]);

            $saveFile = function ($file, $fieldId, $wajib) use ($ticket) {
                if (!$file instanceof \Illuminate\Http\UploadedFile || !$file->isValid()) return;
                // Bypass getRealPath() bug di Windows: kirim path string
                $path = Storage::disk('public')->putFileAs(
                    "ticket-attachments/{$ticket->id}",
                    $file->getPathname(),
                    $file->hashName()
                );
                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'field_id' => $fieldId,
                    'ticket_log_id' => null,
                    'file_path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size' => $file->getSize(),
                    'wajib' => $wajib,
                ]);
            };

            foreach ((array) $request->file('attachments') as $fieldId => $files) {
                if (!is_array($files)) continue;
                $field = $formFields->firstWhere('id', $fieldId);
                foreach ($files as $file) {
                    $saveFile($file, $fieldId, $field ? (bool) $field->wajib : false);
                }
            }
            foreach ((array) $request->file('general_attachments') as $file) {
                $saveFile($file, null, false);
            }

            if ($initialStatus === 'pending') {
                TicketSlaTracking::create([
                    'ticket_id' => $ticket->id,
                    'sla_response_deadline' => null,
                    'sla_resolution_deadline' => null,
                    'current_tier' => 0,
                    'paused_at' => now(),
                ]);
            } else {
                TicketSlaTracking::create([
                    'ticket_id' => $ticket->id,
                    'sla_response_deadline' => $slaCalculator->calculateResponseDeadline($ticket),
                    'sla_resolution_deadline' => $slaCalculator->calculateResolutionDeadline($ticket),
                    'current_tier' => 0,
                ]);
            }

            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => $admin->id,
                'aksi' => 'dibuat',
                'catatan' => 'Tiket diajukan oleh admin ' . ($admin->name ?? $admin->username),
            ]);

            $subUnit = SubUnit::find($request->sub_unit_id);
            if ($subUnit && $subUnit->is_monitored) {
                $assetName = $request->form_data[(string) $subUnit->monitor_asset_field_id] ?? 'Aset Tidak Diketahui';

                $dateStr = $subUnit->monitor_date_field_id
                    ? ($request->form_data[(string) $subUnit->monitor_date_field_id] ?? now()->format('Y-m-d'))
                    : null;
                $endDateStr = $subUnit->monitor_end_date_field_id
                    ? ($request->form_data[(string) $subUnit->monitor_end_date_field_id] ?? $dateStr ?? now()->format('Y-m-d'))
                    : $dateStr;

                $startFieldVal = $request->form_data[(string) $subUnit->monitor_start_field_id] ?? now();
                $endFieldVal = $request->form_data[(string) $subUnit->monitor_end_field_id] ?? now()->addHour();

                if ($dateStr) {
                    $startDT = Carbon::parse($dateStr . ' ' . Carbon::parse($startFieldVal)->format('H:i:s'));
                    $endDT = Carbon::parse(($endDateStr ?? $dateStr) . ' ' . Carbon::parse($endFieldVal)->format('H:i:s'));
                } else {
                    $startDT = Carbon::parse($startFieldVal);
                    $endDT = Carbon::parse($endFieldVal);
                }

                $bentrok = RoomVehicleBooking::where('nama_aset', $assetName)
                    ->whereNotIn('status', ['reject', 'dibatalkan', 'selesai'])
                    ->where('tanggal_mulai', '<', $endDT)
                    ->where('tanggal_selesai', '>', $startDT)
                    ->exists();

                if ($bentrok) {
                    throw ValidationException::withMessages([
                        'form_data.' . $subUnit->monitor_asset_field_id => 'Aset ini sudah dipesan pada jam tersebut.',
                    ]);
                }

                RoomVehicleBooking::create([
                    'ticket_id' => $ticket->id,
                    'tipe' => $subUnit->monitor_kategori ?? 'Lainnya',
                    'nama_aset' => $assetName,
                    'tanggal_mulai' => $startDT,
                    'tanggal_selesai' => $endDT,
                    'status' => 'open',
                ]);
            }

            return $ticket;
        });

        try {
            $ticket->load('subUnit');
            $recipients = Admin::where('id', '!=', $admin->id)
                ->where(function ($q) use ($ticket) {
                    $q->whereHas('units', fn ($u) => $u->where('units.id', $ticket->unit_id))
                      ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['Super Admin', 'superadmin']));
                })
                ->whereDoesntHave('roles', fn ($r) => $r->where('name', 'Operator'))
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new TicketCreatedAdminNotification($ticket));
            }
        } catch (\Exception $e) {
            \Log::error("Gagal mengirim notifikasi untuk tiket #{$ticket->id}: " . $e->getMessage());
        }

        return redirect()->route('admin.tiket.index')->with('success', 'Tiket berhasil diajukan!');
    }

    public function show(Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (!$ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengakses tiket ini.');
        }

        $ticket->load([
            'user', 'user.divisi', 'user.orgUnit', 'user.jabatan', 'createdByAdmin',
            'unit', 'subUnit', 'orgDivisi', 'orgUnit', 'jabatan',
            'attachments.field', 'attachments.log', 'attachments.log.admin', 'slaTracking',
            'booking',
            'logs' => fn($q) => $q->latest('timestamp'),
            'logs.admin',
            'logs.attachments',
        ]);

        $formFields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get();

        $operators = \App\Models\Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->unit_id);
        })->get(['id', 'name', 'username']);

        return Inertia::render('Admin/Tiketing/Detail', [
            'ticket' => $ticket,
            'formFields' => $formFields,
            'operators' => $operators,
        ]);
    }

    public function updateStatus(Request $request, Ticket $ticket, SlaCalculator $slaCalculator)
    {
        $admin = auth('admin')->user();
        if (!$ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengubah status tiket ini.');
        }

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
            if ($newStatus === 'reject') {
                $ticket->booking->update(['status' => 'reject']);
            } elseif (in_array($newStatus, ['solve', 'on_proses'])) {
                $ticket->booking->update(['status' => 'disetujui']);
            }
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
                
                if (!empty($request->catatan)) {
                    $senderName = auth('admin')->user()->name ?? auth('admin')->user()->username;
                    $url = route('tiket.show', $ticket->id);
                    $ticket->user->notify(new \App\Notifications\TicketCommentPushNotification($ticket, $senderName, $request->catatan, $url));
                }
            }
        } catch (\Exception $e) {
            \Log::error("Gagal mengirim notifikasi status update tiket #{$ticket->id}: " . $e->getMessage());
        }

        // Notifikasi ke Operator & Pengaju admin (jika bukan yang mengubah)
        try {
            $ticket->load('assignedAdmin', 'createdByAdmin');
            $currentAdminId = auth('admin')->id();
            $pengubahName = auth('admin')->user()->name ?? auth('admin')->user()->username;
            $recipients = collect([$ticket->assignedAdmin, $ticket->createdByAdmin])
                ->filter(fn ($a) => $a && (int) $a->id !== (int) $currentAdminId)
                ->unique('id');
            foreach ($recipients as $recipient) {
                $recipient->notify(new \App\Notifications\TicketStatusUpdatedOperatorNotification($ticket, $request->catatan, $pengubahName));
            }
        } catch (\Exception $e) {
            \Log::error("Gagal mengirim notifikasi operator tiket #{$ticket->id}: " . $e->getMessage());
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
        $admin = auth('admin')->user();
        $ticket = $attachment->ticket;
        if (!$ticket || !$ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengakses lampiran ini.');
        }

        if (!Storage::disk('public')->exists($attachment->file_path)) {
            abort(404, 'File tidak ditemukan.');
        }

        return Storage::disk('public')->download($attachment->file_path, $attachment->original_name);
    }

    public function viewAttachment(TicketAttachment $attachment)
    {
        $admin = auth('admin')->user();
        $ticket = $attachment->ticket;
        if (!$ticket || !$ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengakses lampiran ini.');
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

    public function assignOperator(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (!$admin->hasRole(['superadmin', 'Super Admin']) && !$admin->isCoordinator()) {
            abort(403, 'Kamu tidak memiliki hak akses untuk menugaskan operator.');
        }

        $request->validate([
            'assigned_admin_id' => 'required|exists:admins,id',
        ]);

        $admin = \App\Models\Admin::findOrFail($request->assigned_admin_id);

        if (!$admin->units()->where('units.id', $ticket->unit_id)->exists()) {
            return redirect()->back()->with('error', 'Operator tidak terdaftar pada unit layanan ini.');
        }

        $ticket->update(['assigned_admin_id' => $admin->id]);

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => auth('admin')->id(),
            'aksi' => 'assign_operator',
            'catatan' => 'Tiket ditugaskan ke operator: ' . ($admin->name ?? $admin->username),
        ]);

        try {
            $admin->notify(new \App\Notifications\TicketAssignedOperatorNotification($ticket));
        } catch (\Exception $e) {
            \Log::error("Gagal mengirim notifikasi penugasan operator untuk tiket #{$ticket->id}: " . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Operator berhasil ditugaskan.');
    }

    public function cancel(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        $isSuperAdmin = $admin->hasRole(['superadmin', 'Super Admin']);

        if (!$isSuperAdmin && (int) $ticket->created_by_admin_id !== (int) $admin->id) {
            abort(403, 'Hanya pengaju tiket yang dapat membatalkan tiket ini.');
        }

        if ($ticket->status !== 'open') {
            return redirect()->back()->with('error', 'Tiket hanya dapat dibatalkan saat berstatus open.');
        }

        DB::transaction(function () use ($ticket, $admin) {
            $ticket->update(['status' => 'dibatalkan']);
            $ticket->booking?->update(['status' => 'dibatalkan']);

            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => $admin->id,
                'aksi' => 'dibatalkan',
                'catatan' => 'Tiket dibatalkan oleh pengaju (' . $admin->name . ')',
            ]);
        });

        return redirect()->back()->with('success', 'Tiket berhasil dibatalkan.');
    }

    public function cancelBooking(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (!$ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak membatalkan booking tiket ini.');
        }

        if (!$ticket->booking) {
            return redirect()->back()->with('error', 'Tidak ada data booking untuk tiket ini.');
        }

        if ($ticket->booking->tanggal_selesai && \Carbon\Carbon::parse($ticket->booking->tanggal_selesai)->isPast()) {
            return redirect()->back()->with('error', 'Masa peminjaman telah berakhir, tidak dapat dibatalkan.');
        }

        $rawService = $ticket->subUnit?->nama_layanan ?? $ticket->booking?->tipe ?? 'Aset';
        $layananTitle = preg_match('/^peminjaman\s+/i', $rawService)
            ? $rawService
            : (preg_match('/^penggunaan\s+/i', $rawService)
                ? preg_replace('/^penggunaan\s+/i', 'Peminjaman ', $rawService)
                : "Peminjaman {$rawService}");

        $ticket->booking->update(['status' => 'dibatalkan']);
        $ticket->update(['status' => 'dibatalkan']);

        $alasan = $request->input('catatan') ?: "{$layananTitle} dibatalkan oleh Admin dan aset telah dibebaskan.";

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'dibatalkan',
            'catatan' => $alasan,
        ]);

        try {
            $ticket->load('user');
            if ($ticket->user) {
                $ticket->user->notify(new \App\Notifications\TicketStatusUpdatedNotification($ticket, $alasan));
                $ticket->user->notify(new \App\Notifications\BrowserNotification(
                    "Peminjaman Dibatalkan",
                    "{$layananTitle} tiket #{$ticket->formatted_id} telah dibatalkan oleh Admin. Kini tersedia kembali di Live Monitor.",
                    "/tiket/{$ticket->id}"
                ));
            }
        } catch (\Exception $e) {
            \Log::error("Gagal kirim notif cancel booking admin: " . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Booking berhasil dibatalkan. Aset seketika tersedia kembali di Live Monitor.');
    }

    public function kembalikanAlat(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (!$ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak mengakses tiket ini.');
        }

        if (!in_array(strtolower($ticket->status), ['solve', 'selesai'])) {
            return redirect()->back()->with('error', 'Tiket harus berstatus selesai terlebih dahulu sebelum alat dapat dikembalikan.');
        }

        $isWajibKembali = $ticket->subUnit?->wajib_kembali
            || str_contains(strtolower($ticket->subUnit?->nama_layanan ?? ''), 'peminjaman alat')
            || str_contains(strtolower($ticket->subUnit?->nama_layanan ?? ''), 'alat');

        if (!$isWajibKembali) {
            return redirect()->back()->with('error', 'Jenis layanan ini tidak memerlukan pengembalian alat.');
        }

        $validated = $request->validate([
            'kondisi_kembali' => 'nullable|string|in:baik,rusak,tidak_lengkap',
            'catatan_kembali' => 'nullable|string|max:1000',
        ]);

        $kondisi = $validated['kondisi_kembali'] ?? 'baik';
        $catatan = !empty($validated['catatan_kembali']) ? trim($validated['catatan_kembali']) : null;

        $ticket->update([
            'dikembalikan_at' => now(),
            'kondisi_kembali' => $kondisi,
            'catatan_kembali' => $catatan,
        ]);

        $kondisiLabel = match($kondisi) {
            'rusak' => 'Rusak',
            'tidak_lengkap' => 'Tidak Lengkap (Aksesoris Kurang)',
            default => 'Baik (Normal)',
        };

        $logCatatan = 'Alat dikembalikan [Kondisi: ' . $kondisiLabel . '], ditandai oleh Admin ' . ($admin->name ?: $admin->username);
        if ($catatan) {
            $logCatatan .= ' — Catatan: ' . $catatan;
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'alat_dikembalikan',
            'catatan' => $logCatatan,
        ]);

        return redirect()->back()->with('success', 'Alat berhasil ditandai sudah dikembalikan.');
    }

    public function reply(Request $request, Ticket $ticket)
    {
        $admin = auth('admin')->user();
        if (!$ticket->isAccessibleBy($admin)) {
            abort(403, 'Kamu tidak berhak membalas tiket ini.');
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
            'admin_id' => $admin->id,
            'aksi' => 'balasan',
            'catatan' => $request->catatan,
            'timestamp' => now(),
        ]);

        $generalFiles = $request->file('general_attachments');
        if (!empty($generalFiles) && is_array($generalFiles)) {
            foreach ($generalFiles as $file) {
                if (!$file || !is_a($file, \Illuminate\Http\UploadedFile::class) || !$file->isValid()) continue;

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
        \App\Services\TicketChatBridgeService::syncTicketReplyToChat($ticket, $log);

        try {
            if ($ticket->user) {
                $ticket->user->notify(new \App\Notifications\BrowserNotification(
                    "Balasan Baru Tiket #{$ticket->formatted_id}",
                    "Admin " . ($admin->name ?? $admin->username) . " membalas tiket Anda: " . \Illuminate\Support\Str::limit($request->catatan, 80),
                    "/tiket/{$ticket->id}"
                ));
            }
        } catch (\Throwable $e) {
            \Log::error("Gagal kirim notif balasan admin: " . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Balasan berhasil dikirim.');
    }
}
