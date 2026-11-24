<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Notifications\TicketCommentPushNotification;
use App\Notifications\TicketStatusUpdatedNotification;
use App\Notifications\TicketStatusUpdatedOperatorNotification;
use App\Notifications\TicketWorkloadReviewRequestedNotification;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

            if ($lockedTicket->work_hours_resume_status !== null) {
                $calendar = new SlaCalculator;
                if (! $calendar->isWithinWorkingHours($at)) {
                    throw new InvalidArgumentException('Gunakan Lanjutkan Tiket setelah jam kerja buka.');
                }

                $adminId = $lockedTicket->assigned_admin_id !== null ? (int) $lockedTicket->assigned_admin_id : null;
                if ($adminId !== null && TicketWorkQueue::hasOtherActive($lockedTicket, $adminId)) {
                    $resumeStatus = $lockedTicket->work_hours_resume_status;
                    $lockedTicket->work_hours_resume_status = null;
                    $lockedTicket->work_hours_sla_paused_at = null;
                    TicketWorkQueue::place($lockedTicket, $resumeStatus, $at);

                    throw new InvalidArgumentException('Petugas masih menangani tiket lain. Tiket masuk antrean.');
                }

                if ($sla = $lockedTicket->slaTracking()->lockForUpdate()->first()) {
                    $sla->setRelation('ticket', $lockedTicket);
                    if ($sla->isPaused()) {
                        $calendar->resumeSla($sla, $at, true);
                    }
                }

                $resumedStatus = $lockedTicket->work_hours_resume_status;
                $lockedTicket->update([
                    'status' => $resumedStatus,
                    'work_hours_resume_status' => null,
                    'work_hours_sla_paused_at' => null,
                ]);
                TicketLog::withoutEvents(fn () => TicketLog::create([
                    'ticket_id' => $lockedTicket->id,
                    'admin_id' => null,
                    'aksi' => 'work_hours_resumed',
                    'catatan' => 'Jam kerja telah buka. Pengerjaan tiket dilanjutkan otomatis.',
                    'timestamp' => $at,
                ]));
                $oldStatus = $resumedStatus;
                if ($oldStatus === $newStatus) {
                    return;
                }
            }

            if ($newStatus === 'on_proses') {
                if ($lockedTicket->assignment_queued_at !== null) {
                    throw new InvalidArgumentException('Petugas masih menangani tiket lain. Tiket tetap menunggu giliran.');
                }

                $withinWorkHours = (new SlaCalculator)->isWithinWorkingHours($at);
                $operatorBusy = $lockedTicket->assigned_admin_id !== null
                    && TicketWorkQueue::hasOtherActive($lockedTicket, (int) $lockedTicket->assigned_admin_id);

                if ($oldStatus === 'pending'
                    && $lockedTicket->assigned_admin_id !== null
                    && (! $withinWorkHours || $operatorBusy)) {
                    TicketWorkQueue::place($lockedTicket, 'on_proses', $at);

                    if (filled($catatan)) {
                        TicketLog::create([
                            'ticket_id' => $lockedTicket->id,
                            'admin_id' => $actor?->id,
                            'aksi' => 'pending',
                            'catatan' => $catatan,
                            'timestamp' => $at,
                        ]);
                    }

                    return;
                }

                if (! $withinWorkHours) {
                    throw new InvalidArgumentException('Jam kerja belum buka. Tiket belum bisa dilanjutkan.');
                }

                if ($operatorBusy) {
                    throw new InvalidArgumentException('Petugas masih menangani tiket lain. Tiket tetap menunggu giliran.');
                }
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

        if ($ticket->status === 'solve') {
            try {
                self::notifyWorkloadReviewRequested($ticket);
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi penilaian beban tiket #{$ticket->id}: {$e->getMessage()}");
            }
        }

        // Webhook hook to SPARC if eligible
        if (! $skipSparcLoop && class_exists(SparcIntegrationService::class)) {
            app(SparcIntegrationService::class)->notifyIfEligible($ticket, 'ticket.status_updated');
        }
    }

    private static function notifyWorkloadReviewRequested(Ticket $ticket): void
    {
        $fresh = Ticket::query()
            ->whereKey($ticket->id)
            ->awaitingWorkloadAssessment()
            ->first();

        if (! $fresh) {
            return;
        }

        $recipients = Admin::query()
            ->where(function ($q) use ($fresh) {
                $q->whereHas('units', fn ($u) => $u->where('units.id', $fresh->unit_id))
                    ->orWhereHas('units', fn ($u) => $u->whereHas('subUnits', fn ($su) => $su->whereKey($fresh->sub_unit_id)))
                    ->orWhereHas('subUnits', fn ($su) => $su->whereKey($fresh->sub_unit_id));
            })
            ->with(['roles:id,name', 'permissions:id,name'])
            ->get()
            ->filter(fn (Admin $a) => $a->isCoordinator() && ! $a->hasRole(['superadmin', 'Super Admin']))
            ->unique('id')
            ->values();

        if ($recipients->isEmpty()) {
            $recipients = Admin::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['superadmin', 'Super Admin']))->get()->unique('id')->values();
        }

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new TicketWorkloadReviewRequestedNotification($fresh));
        }
    }
}
