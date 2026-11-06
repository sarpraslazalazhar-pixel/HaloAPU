<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Notifications\TicketCommentPushNotification;
use App\Notifications\TicketStatusUpdatedNotification;
use App\Notifications\TicketStatusUpdatedOperatorNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TicketStatusService
{
    /**
     * @var array<string, array<int, string>>
     */
    public const VALID_TRANSITIONS = [
        'open' => ['on_proses', 'reject', 'pending'],
        'on_proses' => ['solve', 'pending', 'reject'],
        'pending' => ['on_proses'],
        'need_revision' => ['solve', 'pending', 'reject'],
    ];

    /**
     * Apply a valid status transition to a ticket, handling SLA tracking, booking sync,
     * audit logging, attachment storage, and notification dispatch.
     *
     * @param  array<int, mixed>  $attachments  UploadedFile instances or path descriptors
     */
    public static function applyTransition(
        Ticket $ticket,
        string $newStatus,
        ?string $catatan = null,
        ?Admin $actor = null,
        array $attachments = [],
        ?string $deliverableUrl = null,
        bool $skipSparcLoop = false,
    ): void {
        $oldStatus = $ticket->status;

        if ($oldStatus === $newStatus) {
            return;
        }

        if (! isset(self::VALID_TRANSITIONS[$oldStatus]) || ! in_array($newStatus, self::VALID_TRANSITIONS[$oldStatus], true)) {
            throw new InvalidArgumentException("Transisi status dari '{$oldStatus}' ke '{$newStatus}' tidak diizinkan.");
        }

        $slaCalculator = new SlaCalculator;
        $ticket->loadMissing('slaTracking');
        $sla = $ticket->slaTracking;

        // SLA: open -> on_proses
        if ($oldStatus === 'open' && $newStatus === 'on_proses') {
            if ($sla && ! $sla->responded_at) {
                $respondedAt = now();
                $sla->update([
                    'responded_at' => $respondedAt,
                    'is_response_breached' => $sla->sla_response_deadline && $respondedAt->gt($sla->sla_response_deadline),
                ]);
            }
        }

        // SLA: pause when entering pending
        if ($newStatus === 'pending' && $oldStatus !== 'pending') {
            if ($sla) {
                $slaCalculator->pauseSla($sla);
            }
        }

        // SLA: resume when leaving pending into on_proses
        if ($oldStatus === 'pending' && $newStatus === 'on_proses') {
            if ($sla) {
                $slaCalculator->resumeSla($sla);
            }
            if ($sla && ! $sla->responded_at) {
                $respondedAt = now();
                $sla->update([
                    'responded_at' => $respondedAt,
                    'is_response_breached' => $sla->sla_response_deadline && $respondedAt->gt($sla->sla_response_deadline),
                ]);
            }
        }

        // SLA: solve
        if ($newStatus === 'solve') {
            if ($sla && ! $sla->resolved_at) {
                $resolvedAt = now();
                $sla->update([
                    'resolved_at' => $resolvedAt,
                    'is_resolution_breached' => $sla->sla_resolution_deadline && $resolvedAt->gt($sla->sla_resolution_deadline),
                ]);
            }
            $ticket->update(['is_result_accepted' => false]);
        }

        $ticket->update(['status' => $newStatus]);

        // Sync asset booking status if linked
        $ticket->loadMissing('booking');
        if ($ticket->booking) {
            if ($newStatus === 'reject') {
                $ticket->booking->update(['status' => 'reject']);
            } elseif (in_array($newStatus, ['solve', 'on_proses'], true)) {
                $ticket->booking->update(['status' => 'disetujui']);
            }
        }

        $finalCatatan = $catatan ?? "Status tiket diubah dari {$oldStatus} menjadi {$newStatus}";
        if ($deliverableUrl) {
            $finalCatatan .= " | Hasil: {$deliverableUrl}";
        }

        $log = TicketLog::create([
            'ticket_id' => $ticket->id,
            'admin_id' => $actor?->id,
            'aksi' => $newStatus,
            'catatan' => $finalCatatan,
        ]);

        if (! empty($attachments)) {
            foreach ($attachments as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
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
                } elseif (is_array($file) && isset($file['file_path'])) {
                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'field_id' => null,
                        'ticket_log_id' => $log->id,
                        'file_path' => $file['file_path'],
                        'original_name' => $file['original_name'] ?? basename($file['file_path']),
                        'mime_type' => $file['mime_type'] ?? 'application/octet-stream',
                        'file_size' => $file['file_size'] ?? 0,
                        'wajib' => false,
                    ]);
                }
            }
        }

        // Notification to user
        try {
            $ticket->loadMissing('user', 'subUnit');
            if ($ticket->user) {
                $ticket->user->notify(new TicketStatusUpdatedNotification($ticket, $finalCatatan));

                if (! empty($finalCatatan)) {
                    $senderName = $actor ? ($actor->name ?? $actor->username) : 'Sistem';
                    $url = route('tiket.show', $ticket->id);
                    $ticket->user->notify(new TicketCommentPushNotification($ticket, $senderName, $finalCatatan, $url));
                }
            }
        } catch (\Throwable $e) {
            Log::error("Gagal mengirim notifikasi status update tiket #{$ticket->id}: ".$e->getMessage());
        }

        // Notification to operator and createdByAdmin
        try {
            $ticket->loadMissing('assignedAdmin', 'createdByAdmin');
            $currentAdminId = $actor?->id;
            $pengubahName = $actor ? ($actor->name ?? $actor->username) : 'Sistem';
            $recipients = collect([$ticket->assignedAdmin, $ticket->createdByAdmin])
                ->filter(fn ($a) => $a && (! $currentAdminId || (int) $a->id !== (int) $currentAdminId))
                ->unique('id');
            foreach ($recipients as $recipient) {
                $recipient->notify(new TicketStatusUpdatedOperatorNotification($ticket, $finalCatatan, $pengubahName));
            }
        } catch (\Throwable $e) {
            Log::error("Gagal mengirim notifikasi operator tiket #{$ticket->id}: ".$e->getMessage());
        }

        // Webhook hook to SPARC if eligible
        if (! $skipSparcLoop && class_exists(SparcIntegrationService::class)) {
            app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.status_updated');
        }
    }
}
