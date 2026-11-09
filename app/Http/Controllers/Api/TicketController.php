<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\FormField;
use App\Models\RoomVehicleBooking;
use App\Models\SubUnit;
use App\Models\SystemConfig;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\TicketSlaTracking;
use App\Models\User;
use App\Notifications\BrowserNotification;
use App\Notifications\RevisionRequestedNotification;
use App\Notifications\TicketAssignedOperatorNotification;
use App\Notifications\TicketCommentPushNotification;
use App\Notifications\TicketCreatedAdminNotification;
use App\Notifications\TicketCreatedUserNotification;
use App\Services\SlaCalculator;
use App\Services\SparcIntegrationService;
use App\Services\TicketChatBridgeService;
use App\Services\TicketStatusService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class TicketController extends Controller
{
    /**
     * Display a listing of user tickets with optional filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = Ticket::with(['subUnit', 'assignedAdmin', 'user', 'slaTracking', 'createdByAdmin']);

        if ($user instanceof Admin) {
            $tab = $request->query('tab', 'masuk');
            if ($tab === 'saya_ajukan' || $tab === 'mine') {
                $query->submittedBy($user);
            } else {
                $query->incomingFor($user);
            }
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id);
                $matchedAdminIds = Admin::where('username', $user->username)
                    ->orWhere('email', $user->email)
                    ->pluck('id')
                    ->filter()
                    ->all();
                if (! empty($matchedAdminIds)) {
                    $q->orWhereIn('created_by_admin_id', $matchedAdminIds);
                }
            });
        }

        // Filter by search keyword
        if ($request->has('search') && ! empty($request->search)) {
            $keyword = trim($request->search);
            $cleanId = str_replace(['#', '-', ' '], '', $keyword);

            $query->where(function ($q) use ($keyword, $cleanId) {
                if (! empty($cleanId)) {
                    $q->where('id', 'like', "%{$cleanId}%");
                }
                $q->orWhere('form_data', 'like', "%{$keyword}%")
                  // Jenis Layanan (SubUnit)
                    ->orWhereHas('subUnit', function ($subQuery) use ($keyword) {
                        $subQuery->where('nama_layanan', 'like', "%{$keyword}%")
                            ->orWhere('deskripsi', 'like', "%{$keyword}%")
                            ->orWhere('monitor_kategori', 'like', "%{$keyword}%");
                    })
                  // Kanal Layanan (Unit)
                    ->orWhereHas('unit', function ($unitQuery) use ($keyword) {
                        $unitQuery->where('nama_unit', 'like', "%{$keyword}%")
                            ->orWhere('deskripsi', 'like', "%{$keyword}%");
                    })
                  // Pemohon Tiket
                    ->orWhereHas('user', function ($userQuery) use ($keyword) {
                        $userQuery->where('name', 'like', "%{$keyword}%")
                            ->orWhere('username', 'like', "%{$keyword}%");
                    })
                  // Operator / Admin Ditugaskan
                    ->orWhereHas('assignedAdmin', function ($adminQuery) use ($keyword) {
                        $adminQuery->where('name', 'like', "%{$keyword}%")
                            ->orWhere('username', 'like', "%{$keyword}%");
                    });
            });
        }

        // Filter by status
        if ($request->has('status') && ! empty($request->status)) {
            $status = $request->status;
            if (is_string($status)) {
                $status = str_contains($status, ',') ? explode(',', $status) : [$status];
            }
            if (is_array($status)) {
                $query->whereIn('status', $status);
            } else {
                $query->where('status', $status);
            }
        }

        // Filter by date range
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $tickets = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        // Transform tickets to match flutter app model
        $formattedTickets = $tickets->getCollection()->map(function ($ticket) {
            return $this->formatTicket($ticket);
        });

        return response()->json([
            'data' => $formattedTickets,
            'meta' => [
                'current_page' => $tickets->currentPage(),
                'last_page' => $tickets->lastPage(),
                'per_page' => $tickets->perPage(),
                'total' => $tickets->total(),
            ],
        ], 200);
    }

    /**
     * Store a newly created ticket.
     */
    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // form_data might be a JSON string if sent via FormData
        $formData = $request->input('form_data');
        if (is_string($formData)) {
            $decoded = json_decode($formData, true);
            if (is_array($decoded)) {
                $formData = $decoded;
            }
        }
        if (! is_array($formData)) {
            $formData = [];
        }

        $validated = $request->validate([
            'sub_unit_id' => 'required|integer',
            'priority' => 'nullable|string',
        ]);

        $subUnit = SubUnit::find($validated['sub_unit_id']);
        if (! $subUnit) {
            return response()->json(['message' => 'Layanan (sub unit) tidak ditemukan.'], 404);
        }

        // Check asset schedule conflict BEFORE creating ticket if monitored
        $startDT = null;
        $endDT = null;
        $assetName = null;
        if ($subUnit->is_monitored) {
            $assetName = $formData[(string) $subUnit->monitor_asset_field_id] ?? 'Aset Tidak Diketahui';

            $dateStr = $subUnit->monitor_date_field_id
                ? ($formData[(string) $subUnit->monitor_date_field_id] ?? now()->format('Y-m-d'))
                : null;

            $endDateStr = $subUnit->monitor_end_date_field_id
                ? ($formData[(string) $subUnit->monitor_end_date_field_id] ?? $dateStr ?? now()->format('Y-m-d'))
                : $dateStr;

            $startFieldVal = $formData[(string) $subUnit->monitor_start_field_id] ?? now();
            $endFieldVal = $formData[(string) $subUnit->monitor_end_field_id] ?? now()->addHour();

            if ($dateStr) {
                $startDT = Carbon::parse($dateStr.' '.Carbon::parse($startFieldVal)->format('H:i:s'));
                $endDT = Carbon::parse(($endDateStr ?? $dateStr).' '.Carbon::parse($endFieldVal)->format('H:i:s'));
            } else {
                $startDT = Carbon::parse($startFieldVal);
                $endDT = Carbon::parse($endFieldVal);
            }

            // Cek bentrok
            $bentrok = RoomVehicleBooking::where('nama_aset', $assetName)
                ->whereNotIn('status', ['reject', 'dibatalkan', 'solve', 'selesai'])
                ->where('tanggal_mulai', '<', $endDT)
                ->where('tanggal_selesai', '>', $startDT)
                ->exists();

            if ($bentrok) {
                return response()->json([
                    'message' => 'Gagal membuat tiket',
                    'errors' => ['form_data.'.$subUnit->monitor_asset_field_id => ['Aset ini sudah dipesan pada jam tersebut.']],
                ], 422);
            }
        }

        // Validate file attachments before saving
        if ($request->hasFile('attachments')) {
            $flatFiles = [];
            foreach ($request->file('attachments') as $files) {
                foreach (is_array($files) ? $files : [$files] as $f) {
                    if ($f) {
                        $flatFiles[] = $f;
                    }
                }
            }
            Validator::make(
                ['files' => $flatFiles],
                ['files.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx']
            )->validate();
        }

        $slaCalculator = new SlaCalculator;
        $initialStatus = $slaCalculator->isWithinWorkingHours(now()) ? 'open' : 'pending';

        $ticket = DB::transaction(function () use (
            $request,
            $user,
            $validated,
            $subUnit,
            $formData,
            $initialStatus,
            $slaCalculator,
            $startDT,
            $endDT,
            $assetName
        ) {
            $ticket = new Ticket;

            if ($user instanceof Admin) {
                $matchedUser = User::where('username', $user->username)
                    ->orWhere('email', $user->email)
                    ->first();
                $ticket->user_id = $matchedUser?->id;
                $ticket->created_by_admin_id = $user->id;
            } else {
                $ticket->user_id = $user->id;
                $ticket->created_by_admin_id = null;
            }
            $ticket->divisi_id = $user->divisi_id ?? null;
            $ticket->org_unit_id = $user->org_unit_id ?? null;
            $ticket->jabatan_id = $user->jabatan_id ?? null;
            $ticket->sub_unit_id = $validated['sub_unit_id'];
            $ticket->unit_id = $subUnit->unit_id;
            $ticket->form_data = $formData;
            $ticket->status = $initialStatus;
            $ticket->priority = $validated['priority'] ?? 'normal';
            $ticket->save();

            // Handle file attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $fieldId => $files) {
                    $filesArr = is_array($files) ? $files : [$files];
                    foreach ($filesArr as $file) {
                        $originalName = $file->getClientOriginalName() ?: 'attachment';
                        $ext = $file->getClientOriginalExtension() ?: ($file->guessExtension() ?: 'jpg');
                        if (! str_contains($originalName, '.')) {
                            $originalName = ($originalName === 'attachment' ? 'foto_'.time() : $originalName).'.'.$ext;
                        }
                        $path = $file->store("ticket-attachments/{$ticket->id}", 'public');
                        TicketAttachment::create([
                            'ticket_id' => $ticket->id,
                            'field_id' => is_numeric($fieldId) ? (int) $fieldId : null,
                            'file_path' => $path,
                            'original_name' => $originalName,
                            'mime_type' => $file->getMimeType() ?: ($file->getClientMimeType() ?: 'image/jpeg'),
                            'file_size' => $file->getSize(),
                        ]);
                    }
                }
            }

            // Create initial log
            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => $user instanceof Admin ? $user->id : null,
                'aksi' => 'dibuat',
                'catatan' => 'Tiket dibuat oleh '.($user->name ?? $user->username),
                'timestamp' => now(),
            ]);

            // SLA Tracking
            if ($initialStatus === 'pending') {
                TicketSlaTracking::create([
                    'ticket_id' => $ticket->id,
                    'sla_response_deadline' => null,
                    'sla_resolution_deadline' => null,
                    'paused_at' => now(),
                    'total_paused_minutes' => 0,
                    'current_tier' => 0,
                ]);
            } else {
                TicketSlaTracking::create([
                    'ticket_id' => $ticket->id,
                    'sla_response_deadline' => $slaCalculator->calculateResponseDeadline($ticket),
                    'sla_resolution_deadline' => $slaCalculator->calculateResolutionDeadline($ticket),
                    'current_tier' => 0,
                ]);
            }

            // Integrasi Live Monitor (Booking Aset Generik)
            if ($subUnit && $subUnit->is_monitored && $startDT && $endDT) {
                RoomVehicleBooking::create([
                    'ticket_id' => $ticket->id,
                    'tipe' => $subUnit->monitor_kategori ?? 'Lainnya',
                    'nama_aset' => $assetName ?? 'Aset Tidak Diketahui',
                    'tanggal_mulai' => $startDT,
                    'tanggal_selesai' => $endDT,
                    'status' => 'open',
                ]);
            }

            return $ticket;
        });

        // Notify admins and user (outside transaction)
        try {
            if ($ticket->user) {
                $ticket->user->notify(new TicketCreatedUserNotification($ticket));
            }
        } catch (\Exception $e) {
            // Silently ignore notification errors
        }
        $this->notifyAdminsNewTicket($ticket);

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.created');
        $ticket->load(['subUnit', 'assignedAdmin', 'user', 'createdByAdmin']);

        return response()->json([
            'data' => $this->formatTicket($ticket),
            'message' => 'Tiket berhasil dibuat',
        ], 201);
    }

    /**
     * Display the specified ticket with full details.
     */
    public function show(string $id, Request $request)
    {
        $user = $request->user();

        $ticket = Ticket::with([
            'subUnit',
            'unit',
            'assignedAdmin',
            'user',
            'createdByAdmin',
            'attachments',
            'csat',
            'slaTracking',
            'logs' => function ($q) {
                $q->orderBy('timestamp', 'desc');
            },
            'logs.admin',
            'logs.attachments',
        ])
            ->where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        // Verify ownership
        $isAdmin = $user instanceof Admin;
        if (! $isAdmin && (int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        // Get form fields for label mapping
        $formFields = FormField::where('sub_unit_id', $ticket->sub_unit_id)
            ->orderBy('urutan')
            ->get();

        $maxRevisions = (int) SystemConfig::getValue('max_revisions', 5);

        // Build detailed response
        $responseData = $this->formatTicket($ticket);

        // Add extra detail fields
        $responseData['formFields'] = $formFields->map(function ($field) {
            return [
                'id' => $field->id,
                'label' => $field->label,
                'type' => $field->tipe_field,
                'required' => (bool) $field->wajib,
            ];
        });

        $operators = Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->subUnit?->unit_id ?? $ticket->unit_id);
        })->get(['id', 'name', 'username']);

        $responseData['operators'] = $operators->map(function ($admin) {
            return [
                'id' => $admin->id,
                'name' => $admin->name ?? $admin->username,
            ];
        });

        $responseData['formData'] = $ticket->form_data ?? [];

        $responseData['attachments'] = $ticket->attachments->map(function ($att) {
            $name = $att->original_name ?: 'attachment';
            if (! str_contains($name, '.')) {
                $ext = match (strtolower($att->mime_type ?? '')) {
                    'image/png' => 'png',
                    'application/pdf' => 'pdf',
                    default => 'jpg'
                };
                $name = $name.'.'.$ext;
            }

            return [
                'id' => $att->id,
                'fieldId' => $att->field_id,
                'fileName' => $name,
                'mimeType' => $att->mime_type ?: 'image/jpeg',
                'fileSize' => $att->file_size,
                'path' => 'storage/'.$att->file_path,
                'url' => url('storage/'.$att->file_path),
            ];
        });

        $responseData['logs'] = $ticket->logs->map(function ($log) {
            return [
                'id' => $log->id,
                'action' => $log->aksi,
                'note' => $log->catatan,
                'adminName' => $log->admin ? $log->admin->name : null,
                'createdAt' => $log->timestamp ? $log->timestamp->toIso8601String() : $log->created_at?->toIso8601String(),
                'isFromAdmin' => $log->admin_id !== null,
                'attachments' => $log->attachments->map(function ($att) {
                    $name = $att->original_name ?: 'attachment';
                    if (! str_contains($name, '.')) {
                        $ext = match (strtolower($att->mime_type ?? '')) {
                            'image/png' => 'png',
                            'application/pdf' => 'pdf',
                            default => 'jpg'
                        };
                        $name = $name.'.'.$ext;
                    }

                    return [
                        'id' => $att->id,
                        'fileName' => $name,
                        'path' => 'storage/'.$att->file_path,
                        'url' => url('storage/'.$att->file_path),
                    ];
                }),
            ];
        });

        $responseData['csat'] = $ticket->csat ? [
            'rating' => $ticket->csat->rating,
            'comment' => $ticket->csat->komentar,
            'createdAt' => $ticket->csat->created_at->toIso8601String(),
        ] : null;

        $responseData['maxRevisions'] = $maxRevisions;
        $responseData['revisionCount'] = $ticket->revision_count ?? 0;
        $responseData['isResultAccepted'] = (bool) $ticket->is_result_accepted;
        $responseData['isRevisionEnabled'] = $ticket->subUnit ? (bool) $ticket->subUnit->is_revision_enabled : false;

        return response()->json(['data' => $responseData], 200);
    }

    /**
     * Reply to a ticket.
     */
    public function reply(Request $request, string $id)
    {
        $user = $request->user();

        $isAdmin = $user instanceof Admin;

        $ticket = Ticket::where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if (! $isAdmin && (int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Akses ditolak'], 403);
        }

        if (in_array($ticket->status, ['solve', 'selesai', 'reject', 'dibatalkan'])) {
            return response()->json(['message' => 'Tidak dapat membalas tiket yang sudah selesai atau dibatalkan'], 422);
        }

        $request->validate([
            'catatan' => 'required|string|max:1000',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        $log = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $isAdmin ? $user->id : null,
            'aksi' => 'balasan',
            'catatan' => $request->catatan,
            'timestamp' => now(),
        ]);

        // Handle attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $path = $file->store("ticket-attachments/{$ticket->id}", 'public');

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

        // Notify admins / user
        if (! $isAdmin) {
            $this->notifyAdminsReply($ticket, $user, $request->catatan);
        } else {
            try {
                $ticket->user?->notify(new BrowserNotification(
                    "Balasan Tiket #{$ticket->formatted_id}",
                    'Admin '.($user->name ?? $user->username).' membalas tiket Anda',
                    "/tiket/{$ticket->id}"
                ));
            } catch (\Throwable $e) {
            }
        }

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.replied');

        return response()->json([
            'data' => [
                'id' => $log->id,
                'action' => $log->aksi,
                'note' => $log->catatan,
                'adminName' => $isAdmin ? $user->name : null,
                'createdAt' => $log->timestamp->toIso8601String(),
                'isFromAdmin' => $isAdmin,
            ],
            'message' => 'Balasan berhasil dikirim',
        ], 201);
    }

    /**
     * Cancel a ticket (only if status is 'open').
     */
    public function cancel(Request $request, string $id)
    {
        $user = $request->user();

        $ticket = Ticket::where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        $isAdmin = $user instanceof Admin;
        $isOwner = $isAdmin
            ? ((int) $ticket->created_by_admin_id === (int) $user->id || $ticket->isAccessibleBy($user))
            : ((int) $ticket->user_id === (int) $user->id);

        if (! $ticket || ! $isOwner) {
            return response()->json(['message' => 'Tiket tidak ditemukan atau akses ditolak'], 404);
        }

        if ($ticket->status !== 'open') {
            return response()->json(['message' => 'Hanya tiket dengan status Open yang bisa dibatalkan'], 422);
        }

        $ticket->update(['status' => 'dibatalkan']);

        if ($ticket->booking) {
            $ticket->booking->update(['status' => 'dibatalkan']);
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'dibatalkan',
            'catatan' => 'Tiket dibatalkan oleh '.($user->name ?? $user->username),
            'timestamp' => now(),
        ]);

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.cancelled');

        return response()->json([
            'message' => 'Tiket berhasil dibatalkan',
        ]);
    }

    /**
     * Cancel room/vehicle booking by Admin.
     */
    public function cancelBooking(Request $request, string $id)
    {
        $admin = $request->user();
        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat membatalkan booking aset'], 403);
        }

        $ticket = Ticket::with(['subUnit', 'booking'])
            ->where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket || ! $ticket->isAccessibleBy($admin)) {
            return response()->json(['message' => 'Tiket tidak ditemukan atau akses ditolak'], 404);
        }

        if (! $ticket->booking) {
            return response()->json(['message' => 'Tidak ada data booking untuk tiket ini.'], 422);
        }

        $ticket->booking->update(['status' => 'dibatalkan']);
        $ticket->update(['status' => 'dibatalkan']);

        $catatan = $request->input('catatan') ?: 'Booking dibatalkan oleh Admin dan aset telah dibebaskan.';

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'dibatalkan',
            'catatan' => $catatan,
            'timestamp' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Booking berhasil dibatalkan. Aset seketika tersedia kembali di Live Monitor.',
            'data' => $this->formatTicket($ticket->fresh()),
        ]);
    }

    /**
     * Accept ticket result.
     */
    public function acceptResult(Request $request, string $id)
    {
        $user = $request->user();

        $ticket = Ticket::with('subUnit')
            ->where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket || (int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Tiket tidak ditemukan atau akses ditolak'], 404);
        }

        if ($ticket->status !== 'solve') {
            return response()->json(['message' => 'Tiket tidak dalam status selesai'], 422);
        }

        $ticket->update(['is_result_accepted' => true]);

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'accepted',
            'catatan' => 'Hasil akhir diterima oleh user.',
            'timestamp' => now(),
        ]);

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.result_accepted');

        return response()->json([
            'message' => 'Hasil telah diterima',
        ]);
    }

    /**
     * Request revision on a solved ticket.
     */
    public function requestRevision(Request $request, string $id)
    {
        $user = $request->user();

        $ticket = Ticket::with('subUnit')
            ->where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket || (int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Tiket tidak ditemukan atau akses ditolak'], 404);
        }

        if ($ticket->status !== 'solve') {
            return response()->json(['message' => 'Tiket tidak dalam status selesai'], 422);
        }

        if ($ticket->subUnit && ! $ticket->subUnit->is_revision_enabled) {
            return response()->json(['message' => 'Fitur revisi tidak diaktifkan untuk jenis layanan ini'], 422);
        }

        $maxRevisions = (int) SystemConfig::getValue('max_revisions', 5);

        if ($ticket->revision_count >= $maxRevisions) {
            return response()->json([
                'message' => 'Kamu telah mencapai batas maksimal revisi ('.$maxRevisions.' kali)',
            ], 422);
        }

        $request->validate([
            'catatan' => 'required|string|max:1000',
            'attachments' => 'nullable|array|max:3',
            'attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
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
            'timestamp' => now(),
        ]);

        // Handle attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                if (! $file || ! $file->isValid()) {
                    continue;
                }

                $path = $file->store("ticket-attachments/{$ticket->id}", 'public');

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

        // Notify admins
        $notifiedAdmins = Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->subUnit->unit_id);
        })->get();

        if ($notifiedAdmins->isNotEmpty()) {
            Notification::send($notifiedAdmins, new RevisionRequestedNotification($ticket, $request->catatan));
        }

        app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.revision_requested');

        return response()->json([
            'message' => 'Permintaan revisi berhasil dikirim',
        ]);
    }

    /**
     * Assign operator to ticket (Admin only).
     */
    public function assignOperator(Request $request, string $id)
    {
        $user = $request->user();
        if (! $user instanceof Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat menugaskan operator tiket'], 403);
        }

        if (! $user->hasRole(['superadmin', 'super_admin', 'Super Admin']) && ! $user->isCoordinator()) {
            return response()->json(['message' => 'Kamu tidak memiliki hak akses untuk menugaskan operator'], 403);
        }

        $ticket = Ticket::where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $validated = $request->validate([
            'admin_id' => 'nullable|integer|exists:admins,id',
        ]);

        $adminId = $validated['admin_id'] ?? null;

        if ($adminId) {
            $admin = Admin::find($adminId);
            $ticket->update(['assigned_admin_id' => $admin->id]);

            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => $user->id,
                'aksi' => 'assign_operator',
                'catatan' => 'Tiket ditugaskan ke operator: '.($admin->name ?? $admin->username),
                'timestamp' => now(),
            ]);

            try {
                $admin->notify(new TicketAssignedOperatorNotification($ticket));
            } catch (\Throwable $e) {
                \Log::error("Gagal mengirim notifikasi penugasan operator untuk tiket #{$ticket->id}: ".$e->getMessage());
            }

            return response()->json([
                'message' => 'Operator berhasil ditugaskan',
                'data' => $this->formatTicket($ticket->fresh(['subUnit', 'assignedAdmin', 'user'])),
            ]);
        } else {
            $ticket->update(['assigned_admin_id' => null]);

            TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => $user->id,
                'aksi' => 'unassign_operator',
                'catatan' => 'Penugasan operator dilepas',
                'timestamp' => now(),
            ]);

            return response()->json([
                'message' => 'Penugasan operator berhasil dilepas',
                'data' => $this->formatTicket($ticket->fresh(['subUnit', 'assignedAdmin', 'user'])),
            ]);
        }
    }

    /**
     * Change ticket status (Admin only).
     */
    public function changeStatus(Request $request, string $id)
    {
        $user = $request->user();

        if (! $user instanceof Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat mengubah status tiket'], 403);
        }

        $ticket = Ticket::where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if (! $ticket->isAccessibleBy($user)) {
            return response()->json(['message' => 'Kamu tidak memiliki hak akses untuk mengubah status tiket ini.'], 403);
        }
        $request->validate([
            'status' => 'required|in:open,on_proses,solve,reject,dibatalkan,pending',
            'catatan' => 'nullable|string|max:1000',
            'general_attachments' => 'nullable|array|max:3',
            'general_attachments.*' => 'file|max:3072|mimes:jpg,jpeg,png,pdf,doc,docx',
        ]);

        try {
            TicketStatusService::applyTransition(
                $ticket,
                $request->status,
                $request->catatan,
                $user,
                $request->file('general_attachments') ?? []
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Status tiket berhasil diperbarui',
            'data' => $this->formatTicket($ticket->fresh()),
        ]);
    }

    /**
     * Format a ticket for API response.
     */
    private function formatTicket(Ticket $ticket): array
    {
        $flutterStatus = match (strtolower($ticket->status)) {
            'open' => 'open',
            'on_proses' => 'on_proses',
            'solve', 'selesai' => 'solve',
            'reject' => 'reject',
            'dibatalkan' => 'dibatalkan',
            'pending' => 'pending',
            'need_revision' => 'need_revision',
            default => 'open'
        };

        // Eager-load or resolve form field labels if available
        $formData = $ticket->form_data ?? [];
        $resolvedFormData = [];
        if (is_array($formData) && ! empty($formData)) {
            $ticket->loadMissing('subUnit.formFields');
            $formFields = $ticket->subUnit?->formFields;
            if ($formFields && $formFields->isNotEmpty()) {
                $fieldMap = $formFields->pluck('label', 'id')->toArray();
                foreach ($formData as $k => $v) {
                    $keyLabel = $fieldMap[$k] ?? (string) $k;
                    $resolvedFormData[$keyLabel] = $v;
                }
            } else {
                $resolvedFormData = $formData;
            }
        } else {
            $resolvedFormData = $formData;
        }
        $ticket->loadMissing(['subUnit', 'slaTracking', 'booking']);
        $sla = $ticket->slaTracking;

        $endDT = null;
        if ($ticket->booking && $ticket->booking->tanggal_selesai) {
            $endDT = Carbon::parse($ticket->booking->tanggal_selesai);
        } else {
            $formData = $ticket->form_data ?? [];
            if (is_array($formData)) {
                $dateVal = null;
                $timeVal = null;
                foreach ($formData as $k => $v) {
                    if (!is_string($v)) continue;
                    $vTrim = trim($v);
                    if (!$dateVal && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vTrim)) {
                        $dateVal = $vTrim;
                    }
                    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $vTrim)) {
                        $timeVal = $vTrim;
                    }
                }
                if ($dateVal) {
                    try {
                        $endDT = Carbon::parse($dateVal . ' ' . ($timeVal ?? '23:59:59'));
                    } catch (\Throwable $e) {}
                }
            }
        }
        $isBookingPast = $endDT ? now()->gt($endDT) : false;
        $isKendaraan = str_contains(strtolower($ticket->subUnit?->nama_layanan ?? ''), 'kendaraan')
            || str_contains(strtolower($ticket->subUnit?->monitor_kategori ?? ''), 'kendaraan');

        $wajibKembali = (bool) ($ticket->subUnit?->wajib_kembali || str_contains(strtolower($ticket->subUnit?->nama_layanan ?? ''), 'alat'));
        if ($isKendaraan) {
            $wajibKembali = !$isBookingPast || $ticket->dikembalikan_at !== null;
        }

        $responseDeadlineStr = $sla?->sla_response_deadline
            ? Carbon::parse($sla->sla_response_deadline)->translatedFormat('d M Y, H:i')
            : null;
        $resolutionDeadlineStr = $sla?->sla_resolution_deadline
            ? Carbon::parse($sla->sla_resolution_deadline)->translatedFormat('d M Y, H:i')
            : null;

        $isResponseBreached = (bool) ($sla && ($sla->is_response_breached || ($sla->sla_response_deadline && ! $sla->responded_at && now()->gt($sla->sla_response_deadline))));
        $isResolutionBreached = (bool) ($sla && ($sla->is_resolution_breached || ($sla->sla_resolution_deadline && ! $sla->resolved_at && now()->gt($sla->sla_resolution_deadline))));
        $isSlaBreached = $isResponseBreached || $isResolutionBreached;
        $isSlaPaused = (bool) ($sla && $sla->paused_at !== null) || strtolower($ticket->status) === 'pending';

        return [
            'id' => $ticket->formatted_id ?? (string) $ticket->id,
            'title' => $ticket->judul,
            'description' => is_array($resolvedFormData) ? json_encode($resolvedFormData) : (is_array($ticket->form_data) ? json_encode($ticket->form_data) : ($ticket->form_data ?? '')),
            'category' => $ticket->subUnit ? $ticket->subUnit->nama_layanan : 'General',
            'status' => $flutterStatus,
            'createdAt' => $ticket->created_at->toIso8601String(),
            'requesterName' => $ticket->user ? ($ticket->user->name ?? $ticket->user->username ?? '') : ($ticket->createdByAdmin ? ($ticket->createdByAdmin->name ?? $ticket->createdByAdmin->username ?? 'Admin') : ''),
            'assignedTo' => $ticket->assignedAdmin ? $ticket->assignedAdmin->name : null,
            'priority' => $ticket->priority,
            'sla_response_time' => $responseDeadlineStr,
            'sla_resolution_time' => $resolutionDeadlineStr,
            'sla_response_deadline' => $sla?->sla_response_deadline ? (is_string($sla->sla_response_deadline) ? $sla->sla_response_deadline : $sla->sla_response_deadline->toIso8601String()) : null,
            'sla_resolution_deadline' => $sla?->sla_resolution_deadline ? (is_string($sla->sla_resolution_deadline) ? $sla->sla_resolution_deadline : $sla->sla_resolution_deadline->toIso8601String()) : null,
            'is_sla_breached' => $isSlaBreached,
            'is_sla_paused' => $isSlaPaused,
            'dikembalikan_at' => $ticket->dikembalikan_at ? $ticket->dikembalikan_at->format('d M Y, H:i') : null,
            'kondisi_kembali' => $ticket->kondisi_kembali,
            'catatan_kembali' => $ticket->catatan_kembali,
            'wajib_kembali' => (bool) $wajibKembali,
            'is_booking_past' => (bool) $isBookingPast,
            'booking_end_time' => $endDT?->toIso8601String(),
            'replies' => [],
        ];
    }

    /**
     * Notify admins about a new ticket.
     */
    private function notifyAdminsNewTicket(Ticket $ticket)
    {
        try {
            $ticket->loadMissing(['subUnit', 'user']);
            $notifiedAdmins = Admin::where(function ($q) use ($ticket) {
                $q->whereHas('units', function ($query) use ($ticket) {
                    $query->where('units.id', $ticket->unit_id ?? $ticket->subUnit?->unit_id);
                })->orWhereHas('roles', function ($roleQuery) {
                    $roleQuery->whereIn('name', ['Super Admin', 'superadmin', 'super_admin']);
                });
            })->whereDoesntHave('roles', function ($q) {
                $q->where('name', 'Operator');
            })->get();

            if ($notifiedAdmins->isNotEmpty()) {
                Notification::send($notifiedAdmins, new TicketCreatedAdminNotification($ticket));
            } else {
                Notification::send(new AnonymousNotifiable, new TicketCreatedAdminNotification($ticket));
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Gagal mengirim notifikasi admin tiket baru #{$ticket->id}: ".$e->getMessage());
        }
    }

    /**
     * Notify admins about a ticket reply.
     */
    private function notifyAdminsReply(Ticket $ticket, $user, string $message)
    {
        try {
            $ticket->loadMissing('subUnit');
            $notifiedAdmins = Admin::whereHas('units', function ($query) use ($ticket) {
                $query->where('units.id', $ticket->subUnit?->unit_id);
            })->orWhereHas('roles', function ($q) {
                $q->whereIn('name', ['superadmin', 'Super Admin']);
            })->get();

            if ($notifiedAdmins->isNotEmpty() && class_exists(TicketCommentPushNotification::class)) {
                $senderName = $user->name ?? $user->username;
                $url = route('admin.tiket.show', $ticket->id);
                Notification::send($notifiedAdmins, new TicketCommentPushNotification($ticket, $senderName, $message, $url));
            }
        } catch (\Exception $e) {
            // Silently ignore notification errors
        }
    }

    /**
     * Serve attachment file with CORS headers for Flutter Mobile & Web
     */
    public function serveAttachment(Request $request)
    {
        $path = $request->query('path');
        if (! $path) {
            return response()->json(['message' => 'Path file tidak disertakan'], 400);
        }

        // Hapus prefix storage/ atau public/ jika ada
        $cleanPath = preg_replace('/^(storage\/|public\/|\/)+/', '', $path);

        // Path traversal protection
        if (str_contains($cleanPath, '..') || str_contains($cleanPath, '\\')) {
            return response()->json(['message' => 'Path file tidak valid'], 400);
        }

        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => '*',
        ];

        if (Storage::disk('public')->exists($cleanPath)) {
            return response()->file(Storage::disk('public')->path($cleanPath), $headers);
        }

        if (file_exists(storage_path('app/public/'.$cleanPath))) {
            return response()->file(storage_path('app/public/'.$cleanPath), $headers);
        }

        if (file_exists(public_path('storage/'.$cleanPath))) {
            return response()->file(public_path('storage/'.$cleanPath), $headers);
        }

        return response()->json(['message' => 'File tidak ditemukan: '.$cleanPath], 404);
    }

    /**
     * Mark asset / equipment as returned.
     */
    public function kembalikanAlat(Request $request, string $id)
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat menandai pengembalian aset/alat'], 403);
        }

        $ticket = Ticket::with(['subUnit', 'booking'])
            ->where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if (! $ticket->isAccessibleBy($admin)) {
            return response()->json(['message' => 'Kamu tidak memiliki hak akses ke tiket ini.'], 403);
        }

        $request->validate([
            'kondisi_kembali' => 'nullable|string|in:baik,rusak,tidak_lengkap',
            'catatan_kembali' => 'nullable|string|max:1000',
        ]);

        $kondisi = $request->kondisi_kembali ?? 'baik';
        $catatan = ! empty($request->catatan_kembali) ? trim($request->catatan_kembali) : null;

        $ticket->update([
            'dikembalikan_at' => now(),
            'kondisi_kembali' => $kondisi,
            'catatan_kembali' => $catatan,
        ]);

        // Jika ada booking kendaraan / ruangan, tandai selesai agar aset kembali Tersedia
        if ($ticket->booking) {
            $ticket->booking->update(['status' => 'selesai']);
        }

        $kondisiLabel = match ($kondisi) {
            'rusak' => 'Rusak',
            'tidak_lengkap' => 'Tidak Lengkap (Aksesoris Kurang)',
            default => 'Baik (Normal)',
        };

        $logCatatan = 'Alat/aset dikembalikan [Kondisi: '.$kondisiLabel.'], ditandai oleh Admin '.($admin->name ?: $admin->username);
        if ($catatan) {
            $logCatatan .= ' — Catatan: '.$catatan;
        }

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'alat_dikembalikan',
            'catatan' => $logCatatan,
            'timestamp' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Alat/aset berhasil ditandai sudah dikembalikan',
            'data' => $this->formatTicket($ticket->fresh()),
        ], 200);
    }

    /**
     * Change ticket priority.
     */
    public function changePriority(Request $request, string $id)
    {
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat mengubah prioritas tiket'], 403);
        }

        $ticket = Ticket::where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        if (! $ticket->isAccessibleBy($admin)) {
            return response()->json(['message' => 'Kamu tidak memiliki hak akses ke tiket ini.'], 403);
        }

        $request->validate([
            'priority' => 'required|string',
        ]);

        $oldPriority = $ticket->priority;
        $newPriority = $request->priority;

        $ticket->update(['priority' => $newPriority]);

        // Hitung ulang deadline SLA jika prioritas diubah saat tiket masih aktif
        $ticket->loadMissing('slaTracking');
        if ($ticket->slaTracking && ! in_array($ticket->status, ['solve', 'reject', 'dibatalkan'])) {
            $slaCalculator = new SlaCalculator;
            $responseDeadline = $slaCalculator->calculateResponseDeadline($ticket);
            $resolutionDeadline = $slaCalculator->calculateResolutionDeadline($ticket);

            $ticket->slaTracking->update([
                'sla_response_deadline' => $responseDeadline,
                'sla_resolution_deadline' => $resolutionDeadline,
            ]);
        }
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin->id,
            'aksi' => 'update_priority',
            'catatan' => 'Prioritas diubah dari '.($oldPriority ?? 'normal')." menjadi $newPriority oleh Admin ".($admin->name ?: $admin->username),
            'timestamp' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Prioritas berhasil diubah',
            'data' => $this->formatTicket($ticket->fresh()),
        ], 200);
    }
}
