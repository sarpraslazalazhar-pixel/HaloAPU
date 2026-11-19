<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Notifications\TicketCommentPushNotification;
use App\Notifications\TicketStatusUpdatedNotification;
use App\Notifications\TicketStatusUpdatedOperatorNotification;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        $updated = TicketWorkQueue::mutate($ticket, null, function (Ticket $lockedTicket, Carbon $at) use ($newStatus, $catatan, $actor, $attachments, $deliverableUrl, $skipSparcLoop) {
            $oldStatus = $lockedTicket->status;
            if ($oldStatus === $newStatus) {
                return;
            }

            if ($newStatus === 'on_proses' && ($lockedTicket->assignment_queued_at !== null
                || ($lockedTicket->assigned_admin_id !== null && TicketWorkQueue::hasOtherActive($lockedTicket, (int) $lockedTicket->assigned_admin_id)))) {
                throw new InvalidArgumentException('Petugas masih menangani tiket lain. Tiket tetap menunggu giliran.');
            }

            if (! in_array($newStatus, self::VALID_TRANSITIONS[$oldStatus] ?? [], true)) {
                throw new InvalidArgumentException("Transisi status dari '{$oldStatus}' ke '{$newStatus}' tidak diizinkan.");
            }

            $finalCatatan = self::applyLockedTransition($lockedTicket, $newStatus, $catatan, $actor, $attachments, $deliverableUrl, $at);
            DB::afterCommit(fn () => self::notifyTransition($lockedTicket, $actor, $finalCatatan, $skipSparcLoop));
        });

        $ticket->setRawAttributes($updated->getAttributes(), true);
    }

    private static function applyLockedTransition(
        Ticket $ticket,
        string $newStatus,
        ?string $catatan,
        ?Admin $actor,
        array $attachments,
        ?string $deliverableUrl,
        Carbon $at,
    ): string {
        $oldStatus = $ticket->status;
        $sla = $ticket->slaTracking()->lockForUpdate()->first();

        if ($sla) {
            $sla->setRelation('ticket', $ticket);
            if ($newStatus === 'pending') {
                (new SlaCalculator)->pauseSla($sla, $at);
            } elseif ($newStatus === 'on_proses') {
                if ($oldStatus === 'pending') {
                    (new SlaCalculator)->resumeSla($sla, $at);
                }
                if ($sla->responded_at === null) {
                    $sla->update([
                        'responded_at' => $at,
                        'is_response_breached' => $sla->is_response_breached
                            || ($sla->sla_response_deadline && $at->gt($sla->sla_response_deadline)),
                    ]);
                }
            } elseif ($newStatus === 'solve' && $sla->resolved_at === null) {
                $sla->update([
                    'resolved_at' => $at,
                    'is_resolution_breached' => $sla->is_resolution_breached
                        || ($sla->sla_resolution_deadline && $at->gt($sla->sla_resolution_deadline)),
                ]);
            }
        }

        $ticket->status = $newStatus;
        if ($newStatus === 'solve') {
            $ticket->is_result_accepted = false;
        }
        $ticket->save();

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

        return $finalCatatan;
    }

    private static function notifyTransition(Ticket $ticket, ?Admin $actor, string $finalCatatan, bool $skipSparcLoop): void
    {
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
