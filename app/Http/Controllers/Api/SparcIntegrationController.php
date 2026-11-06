<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Services\SparcIntegrationService;
use App\Services\TicketChatBridgeService;
use App\Services\TicketStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SparcIntegrationController extends Controller
{
    public function __construct(
        protected SparcIntegrationService $sparcService
    ) {}

    /**
     * Health check and handshake endpoint for SPARC.
     */
    public function ping(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app' => 'HaloAPU',
            'version' => app()->version(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * List eligible Humas tickets (Reels & Feed/Meme) with optional delta filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Ticket::with([
            'user.divisi',
            'user.orgUnit',
            'createdByAdmin',
            'assignedAdmin',
            'subUnit.unit',
            'subUnit.formFields',
            'attachments',
            'logs.admin',
            'slaTracking',
        ]);

        if ($request->filled('updated_since')) {
            try {
                $since = Carbon::parse($request->query('updated_since'));
                $query->where('updated_at', '>=', $since);
            } catch (\Throwable) {
                // Ignore invalid date string
            }
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            $cleanId = str_replace('-', '', $search);
            $query->where(function ($q) use ($search, $cleanId) {
                $q->where('id', 'like', "%{$cleanId}%")
                    ->orWhereHas('user', function ($uq) use ($search) {
                        $uq->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%");
                    });
            });
        }

        $allTickets = $query->orderByDesc('updated_at')->get();

        // Filter in memory for exact sub_unit and dynamic form field criteria
        $filtered = $allTickets->filter(fn (Ticket $t) => $this->sparcService->matchesSparcFilter($t) !== null);

        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $total = $filtered->count();

        $pagedTickets = $filtered->slice(($page - 1) * $perPage, $perPage);
        $mapped = $pagedTickets->map(fn (Ticket $t) => $this->sparcService->serializeTicketForSparc($t))->values();

        return response()->json([
            'data' => $mapped,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'synced_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Show single eligible ticket.
     */
    public function show(string $id): JsonResponse
    {
        $cleanId = str_replace('-', '', $id);
        /** @var Ticket|null $ticket */
        $ticket = Ticket::with([
            'user.divisi',
            'user.orgUnit',
            'createdByAdmin',
            'assignedAdmin',
            'subUnit.unit',
            'subUnit.formFields',
            'attachments',
            'logs.admin',
            'slaTracking',
        ])
            ->where('id', $cleanId)
            ->orWhere('id', $id)
            ->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan.'], 404);
        }

        if (! $this->sparcService->matchesSparcFilter($ticket)) {
            return response()->json(['message' => 'Tiket tidak berada dalam cakupan integrasi SPARC Humas.'], 404);
        }

        return response()->json([
            'data' => $this->sparcService->serializeTicketForSparc($ticket),
        ]);
    }

    /**
     * Transition ticket status from SPARC (e.g. on_proses, pending, solve, reject).
     */
    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $cleanId = str_replace('-', '', $id);
        /** @var Ticket|null $ticket */
        $ticket = Ticket::where('id', $cleanId)->orWhere('id', $id)->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:on_proses,pending,solve,reject'],
            'catatan' => ['nullable', 'string', 'max:1000'],
            'deliverable_url' => ['nullable', 'url', 'max:500'],
            'actor_name' => ['nullable', 'string', 'max:100'],
            'actor_email' => ['nullable', 'email', 'max:100'],
        ]);

        $actor = $this->sparcService->resolveActingAdmin(
            $validated['actor_email'] ?? null,
            $validated['actor_name'] ?? 'SPARC System'
        );

        try {
            TicketStatusService::applyTransition(
                $ticket,
                $validated['status'],
                $validated['catatan'] ?? null,
                $actor,
                [],
                $validated['deliverable_url'] ?? null,
                skipSparcLoop: true
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Status tiket berhasil diperbarui.',
            'data' => $this->sparcService->serializeTicketForSparc($ticket->fresh()),
        ]);
    }

    /**
     * Send a comment / reply from SPARC to the HaloAPU ticket requester.
     */
    public function reply(Request $request, string $id): JsonResponse
    {
        $cleanId = str_replace('-', '', $id);
        /** @var Ticket|null $ticket */
        $ticket = Ticket::where('id', $cleanId)->orWhere('id', $id)->first();

        if (! $ticket) {
            return response()->json(['message' => 'Tiket tidak ditemukan.'], 404);
        }

        $validated = $request->validate([
            'catatan' => ['required', 'string', 'max:1000'],
            'actor_name' => ['nullable', 'string', 'max:100'],
            'actor_email' => ['nullable', 'email', 'max:100'],
        ]);

        $actor = $this->sparcService->resolveActingAdmin(
            $validated['actor_email'] ?? null,
            $validated['actor_name'] ?? 'Humas SPARC'
        );

        $log = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $actor?->id,
            'aksi' => 'balasan',
            'catatan' => $validated['catatan'],
        ]);

        try {
            TicketChatBridgeService::syncTicketReplyToChat($ticket, $log);
        } catch (\Throwable) {
            // Chat bridge is optional/best-effort
        }

        return response()->json([
            'message' => 'Balasan berhasil dikirimkan ke tiket HaloAPU.',
        ]);
    }

    /**
     * Securely proxy attachment binary file to SPARC without requiring public S3 / web access.
     */
    public function streamAttachment(int|string $attachmentId): StreamedResponse|JsonResponse
    {
        /** @var TicketAttachment|null $attachment */
        $attachment = TicketAttachment::with('ticket')->find($attachmentId);

        if (! $attachment) {
            return response()->json(['message' => 'Lampiran tidak ditemukan.'], 404);
        }

        if (! $this->sparcService->matchesSparcFilter($attachment->ticket)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        if (! Storage::disk('public')->exists($attachment->file_path)) {
            return response()->json(['message' => 'File fisik tidak ditemukan pada storage.'], 404);
        }

        return Storage::disk('public')->response(
            $attachment->file_path,
            $attachment->original_name,
            [
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            ]
        );
    }
}
