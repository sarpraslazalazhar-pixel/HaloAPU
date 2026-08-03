<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\TicketAttachment;
use App\Models\FormField;
use App\Models\SystemConfig;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TicketController extends Controller
{
    /**
     * Display a listing of user tickets with optional filters.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $query = Ticket::with(['subUnit', 'assignedAdmin', 'user']);

        if ($user instanceof \App\Models\Admin) {
            if ($user->hasRole('superadmin')) {
                // Superadmin sees all tickets
            } else {
                $user->load('units');
                $unitIds = $user->units->pluck('id')->toArray();
                $query->whereHas('subUnit', function ($q) use ($unitIds) {
                    $q->whereIn('unit_id', $unitIds);
                });
            }
        } else {
            $query->where('user_id', $user->id);
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            if (is_array($request->status)) {
                $query->whereIn('status', $request->status);
            } else {
                $query->where('status', $request->status);
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
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if ($user instanceof \App\Models\Admin) {
            return response()->json(['message' => 'Admin tidak dapat membuat tiket. Silakan login sebagai User.'], 403);
        }

        // form_data might be a JSON string if sent via FormData
        $formData = $request->input('form_data');
        if (is_string($formData)) {
            $formData = json_decode($formData, true);
        }

        $validated = $request->validate([
            'sub_unit_id' => 'required|integer',
            'priority' => 'nullable|string',
        ]);

        $ticket = new Ticket();
        $ticket->user_id = $user->id;
        $ticket->divisi_id = $user->divisi_id ?? null;
        $ticket->org_unit_id = $user->org_unit_id ?? null;
        $ticket->jabatan_id = $user->jabatan_id ?? null;
        $ticket->sub_unit_id = $validated['sub_unit_id'];
        $ticket->form_data = $formData ?? [];
        $ticket->status = 'open';
        $ticket->priority = $validated['priority'] ?? 'normal';

        $ticket->save();

        // Handle file attachments
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $fieldId => $files) {
                $filesArr = is_array($files) ? $files : [$files];
                foreach ($filesArr as $file) {
                    $path = $file->store("ticket-attachments/{$ticket->id}", 'public');
                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'field_id' => $fieldId,
                        'file_path' => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getClientMimeType(),
                        'file_size' => $file->getSize(),
                    ]);
                }
            }
        }

        // Create initial log
        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => null,
            'aksi' => 'dibuat',
            'catatan' => 'Tiket dibuat oleh ' . ($user->name ?? $user->username),
            'timestamp' => now(),
        ]);

        // Notify admins
        $this->notifyAdminsNewTicket($ticket);

        $ticket->load(['subUnit', 'assignedAdmin', 'user']);

        return response()->json([
            'data' => $this->formatTicket($ticket),
            'message' => 'Tiket berhasil dibuat'
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
            'attachments',
            'csat',
            'logs' => function ($q) {
                $q->orderBy('timestamp', 'desc');
            },
            'logs.admin',
            'logs.attachments',
        ])
            ->where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        // Verify ownership
        $isAdmin = $user instanceof \App\Models\Admin;
        if (!$isAdmin && (int) $ticket->user_id !== (int) $user->id) {
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

        $responseData['formData'] = $ticket->form_data ?? [];

        $responseData['attachments'] = $ticket->attachments->map(function ($att) {
            return [
                'id' => $att->id,
                'fieldId' => $att->field_id,
                'fileName' => $att->original_name,
                'mimeType' => $att->mime_type,
                'fileSize' => $att->file_size,
                'url' => url('storage/' . $att->file_path),
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
                    return [
                        'id' => $att->id,
                        'fileName' => $att->original_name,
                        'url' => url('storage/' . $att->file_path),
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

        $isAdmin = $user instanceof \App\Models\Admin;

        $ticket = Ticket::where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }
        
        if (!$isAdmin && (int) $ticket->user_id !== (int) $user->id) {
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
                if (!$file || !$file->isValid()) continue;

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
        $this->notifyAdminsReply($ticket, $user, $request->catatan);

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

        if (!$ticket || (int) $ticket->user_id !== (int) $user->id) {
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
            'catatan' => 'Tiket dibatalkan oleh ' . ($user->name ?? $user->username),
            'timestamp' => now(),
        ]);

        return response()->json([
            'message' => 'Tiket berhasil dibatalkan',
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

        if (!$ticket || (int) $ticket->user_id !== (int) $user->id) {
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

        if (!$ticket || (int) $ticket->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Tiket tidak ditemukan atau akses ditolak'], 404);
        }

        if ($ticket->status !== 'solve') {
            return response()->json(['message' => 'Tiket tidak dalam status selesai'], 422);
        }

        if ($ticket->subUnit && !$ticket->subUnit->is_revision_enabled) {
            return response()->json(['message' => 'Fitur revisi tidak diaktifkan untuk jenis layanan ini'], 422);
        }

        $maxRevisions = (int) SystemConfig::getValue('max_revisions', 5);

        if ($ticket->revision_count >= $maxRevisions) {
            return response()->json([
                'message' => 'Anda telah mencapai batas maksimal revisi (' . $maxRevisions . ' kali)'
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
                if (!$file || !$file->isValid()) continue;

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
        $notifiedAdmins = \App\Models\Admin::whereHas('units', function ($query) use ($ticket) {
            $query->where('units.id', $ticket->subUnit->unit_id);
        })->get();

        if ($notifiedAdmins->isNotEmpty()) {
            \Illuminate\Support\Facades\Notification::send($notifiedAdmins, new \App\Notifications\RevisionRequestedNotification($ticket, $request->catatan));
        }

        return response()->json([
            'message' => 'Permintaan revisi berhasil dikirim',
        ]);
    }

    /**
     * Change ticket status (Admin only).
     */
    public function changeStatus(Request $request, string $id)
    {
        $user = $request->user();

        if (!$user instanceof \App\Models\Admin) {
            return response()->json(['message' => 'Hanya Admin yang dapat mengubah status tiket'], 403);
        }

        $ticket = Ticket::where('id', str_replace('-', '', $id))
            ->orWhere('id', $id)
            ->first();

        if (!$ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan'], 404);
        }

        $request->validate([
            'status' => 'required|in:open,on_proses,solve,reject,dibatalkan',
        ]);

        $oldStatus = $ticket->status;
        $newStatus = $request->status;

        if ($oldStatus === $newStatus) {
            return response()->json(['message' => 'Status sudah ' . $newStatus], 422);
        }

        $ticket->update(['status' => $newStatus]);

        TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $user->id,
            'aksi' => 'update_status',
            'catatan' => "Status tiket diubah dari {$oldStatus} menjadi {$newStatus} oleh {$user->name}",
            'timestamp' => now(),
        ]);

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
        return [
            'id' => $ticket->formatted_id ?? (string) $ticket->id,
            'title' => $ticket->judul,
            'description' => isset($ticket->form_data) ? (is_array($ticket->form_data) ? json_encode($ticket->form_data) : $ticket->form_data) : '',
            'category' => $ticket->subUnit ? $ticket->subUnit->nama_layanan : 'General',
            'status' => $ticket->status,
            'createdAt' => $ticket->created_at->toIso8601String(),
            'requesterName' => $ticket->user ? $ticket->user->name : '',
            'assignedTo' => $ticket->assignedAdmin ? $ticket->assignedAdmin->name : null,
            'replies' => [],
        ];
    }

    /**
     * Notify admins about a new ticket.
     */
    private function notifyAdminsNewTicket(Ticket $ticket)
    {
        try {
            $ticket->loadMissing('subUnit');
            $notifiedAdmins = \App\Models\Admin::whereHas('units', function ($query) use ($ticket) {
                $query->where('units.id', $ticket->subUnit?->unit_id);
            })->orWhereHas('roles', function ($q) {
                $q->where('name', 'superadmin');
            })->get();

            if ($notifiedAdmins->isNotEmpty() && class_exists(\App\Notifications\NewTicketPushNotification::class)) {
                \Illuminate\Support\Facades\Notification::send($notifiedAdmins, new \App\Notifications\NewTicketPushNotification($ticket));
            }
        } catch (\Exception $e) {
            // Silently ignore notification errors to not block ticket creation
        }
    }

    /**
     * Notify admins about a ticket reply.
     */
    private function notifyAdminsReply(Ticket $ticket, $user, string $message)
    {
        try {
            $ticket->loadMissing('subUnit');
            $notifiedAdmins = \App\Models\Admin::whereHas('units', function ($query) use ($ticket) {
                $query->where('units.id', $ticket->subUnit?->unit_id);
            })->orWhereHas('roles', function ($q) {
                $q->where('name', 'superadmin');
            })->get();

            if ($notifiedAdmins->isNotEmpty() && class_exists(\App\Notifications\TicketCommentPushNotification::class)) {
                $senderName = $user->name ?? $user->username;
                $url = route('admin.tiket.show', $ticket->id);
                \Illuminate\Support\Facades\Notification::send($notifiedAdmins, new \App\Notifications\TicketCommentPushNotification($ticket, $senderName, $message, $url));
            }
        } catch (\Exception $e) {
            // Silently ignore notification errors
        }
    }
}
