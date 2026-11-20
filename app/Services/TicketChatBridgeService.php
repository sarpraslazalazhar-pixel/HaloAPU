<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\User;
use App\Notifications\TicketCommentPushNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Two-way bridge between the ticket timeline (`ticket_logs`) and the shared
 * chat rooms (`conversations`/`messages`).
 *
 * Identity is `messages.ticket_log_id`: one reply log maps to exactly one chat
 * message, so identical text, attachment-only replies and replayed imports can
 * never be mistaken for each other or duplicated.
 */
class TicketChatBridgeService
{
    /**
     * Mirror one ticket reply into its chat room. Returns null when the ticket
     * has no requester identity (legacy admin-only rows).
     */
    public static function syncTicketReplyToChat(Ticket $ticket, TicketLog $log): ?Message
    {
        $userId = $ticket->user_id ? (int) $ticket->user_id : null;
        $createdByAdminId = $ticket->created_by_admin_id ? (int) $ticket->created_by_admin_id : null;

        if (! $userId && ! $createdByAdminId) {
            return null;
        }

        if ($log->admin_id) {
            $senderType = Admin::class;
            $senderId = (int) $log->admin_id;
        } elseif ($userId) {
            $senderType = User::class;
            $senderId = $userId;
        } else {
            $senderType = Admin::class;
            $senderId = $createdByAdminId;
        }

        $body = trim((string) $log->catatan);
        $log->loadMissing('attachments');
        if ($body === '' && $log->attachments->isNotEmpty()) {
            $body = '[Lampiran Tiket]';
        }

        $ts = $log->timestamp ?? now();

        [$message, $created] = DB::transaction(function () use ($ticket, $log, $userId, $createdByAdminId, $senderType, $senderId, $body, $ts) {
            $conversation = Conversation::firstOrCreate(
                ['type' => 'ticket', 'ticket_id' => $ticket->id],
                [
                    'user_id' => $userId,
                    'admin_one_id' => $ticket->assigned_admin_id,
                    'admin_two_id' => $createdByAdminId,
                    'last_message_at' => $ts,
                ]
            );

            /** @var Message $message */
            $message = Message::withTrashed()->firstOrCreate(
                ['ticket_log_id' => $log->id],
                [
                    'conversation_id' => $conversation->id,
                    'sender_type' => $senderType,
                    'sender_id' => $senderId,
                    'ticket_id' => $ticket->id,
                    'body' => $body,
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]
            );

            ChatInboxService::copyLogAttachments($log, $message);

            if (! $message->wasRecentlyCreated) {
                return [$message, false];
            }

            MessageRead::firstOrCreate([
                'message_id' => $message->id,
                'user_type' => $senderType,
                'user_id' => $senderId,
            ], ['read_at' => $ts]);

            if ($conversation->last_message_at === null || $ts->gt($conversation->last_message_at)) {
                $conversation->updateQuietly(['last_message_at' => $ts]);
            }

            return [$message, true];
        });

        if ($created) {
            self::afterCommit(fn () => self::emitChatMessage($message));
        }

        return $message;
    }

    /**
     * Mirror one chat message into the ticket timeline. Only ticket rooms are
     * mirrored; direct conversations keep their old behaviour.
     */
    public static function syncChatMessageToTicket(Message $message): ?TicketLog
    {
        $conversation = $message->conversation;

        // A ticket room always owns one ticket; direct rooms keep the legacy
        // resolution (explicit tag, reply parent, then the requester's ticket).
        if ($conversation && $conversation->type === 'ticket' && $conversation->ticket_id) {
            $ticket = $conversation->ticket;

            if (! $ticket) {
                return null;
            }
        } else {
            $ticket = self::resolveLegacyTicket($message);

            if (! $ticket) {
                return null;
            }
        }

        [$log, $created] = DB::transaction(function () use ($message, $ticket) {
            /** @var Message $locked */
            $locked = Message::whereKey($message->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->ticket_id !== $ticket->id) {
                $locked->update(['ticket_id' => $ticket->id]);
            }

            if ($locked->ticket_log_id) {
                $existing = TicketLog::find($locked->ticket_log_id);

                if ($existing) {
                    return [$existing, false];
                }
            }

            $attachments = $locked->attachments;
            $body = trim((string) $locked->body);
            if ($body === '' && $attachments->isNotEmpty()) {
                $body = '[Lampiran Chat]';
            }

            $isAdmin = $locked->sender_type === Admin::class;

            $log = TicketLog::createQuietly([
                'ticket_id' => $ticket->id,
                'admin_id' => $isAdmin ? $locked->sender_id : null,
                'aksi' => 'balasan',
                'catatan' => $body,
                'timestamp' => now(),
            ]);

            foreach ($attachments as $att) {
                TicketAttachment::create([
                    'ticket_id' => $ticket->id,
                    'field_id' => null,
                    'ticket_log_id' => $log->id,
                    'file_path' => $att->file_path,
                    'original_name' => $att->file_name,
                    'mime_type' => $att->file_type,
                    'file_size' => (int) ($att->file_size ?: 0),
                    'wajib' => false,
                ]);
            }

            $locked->update(['ticket_log_id' => $log->id]);

            return [$log, true];
        });

        if ($created) {
            $log->loadMissing(['admin', 'attachments']);
            self::afterCommit(fn () => $log->broadcastTimeline());
        }

        return $log;
    }

    /**
     * Send reply notifications to the admins who can actually act on the ticket.
     * Never notifies the sender; recipients are deduplicated across sources.
     */
    public static function notifyReplyRecipients(Ticket $ticket, ?Admin $sender, string $message): void
    {
        $ticket->loadMissing(['assignedAdmin', 'createdByAdmin', 'subUnit.unit']);

        $recipients = collect();

        if ($sender === null) {
            $recipients = $recipients->merge(self::serviceTeamAdmins($ticket));
        } elseif ($sender->id !== $ticket->assigned_admin_id) {
            $recipients = $recipients->merge(self::serviceTeamAdmins($ticket));
        }

        if ($ticket->assignedAdmin) {
            $recipients->push($ticket->assignedAdmin);
        }

        if ($ticket->work_hours_resume_status !== null && $ticket->createdByAdmin) {
            $recipients->push($ticket->createdByAdmin);
        }

        $recipients = $recipients
            ->filter(fn ($admin) => $admin && ($sender === null || (int) $admin->id !== (int) $sender->id))
            ->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        $senderName = $sender ? ($sender->name ?? $sender->username) : ($ticket->user?->name ?? $ticket->user?->username ?? 'Pengguna');
        $url = route('admin.tiket.show', $ticket->id);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new TicketCommentPushNotification($ticket, $senderName, $message, $url));
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi balasan tiket #{$ticket->id} ke admin #{$recipient->id}: ".$e->getMessage());
            }
        }
    }

    /** Admin unit layanan plus superadmin legacy, tanpa duplikasi. */
    private static function serviceTeamAdmins(Ticket $ticket): \Illuminate\Support\Collection
    {
        $unitId = $ticket->subUnit?->unit_id ?? $ticket->unit_id;

        return Admin::query()
            ->where(function ($q) use ($unitId) {
                if ($unitId !== null) {
                    $q->whereHas('units', fn ($u) => $u->where('units.id', $unitId));
                }
            })
            ->orWhereHas('roles', fn ($r) => $r->whereIn('name', ['superadmin', 'Super Admin', 'super_admin', 'Superadmin']))
            ->get();
    }

    /**
     * Legacy direct-room resolution, kept for non-ticket conversations.
     */
    private static function resolveLegacyTicket(Message $message): ?Ticket
    {
        $ticketId = $message->ticket_id;

        if (! $ticketId && $message->conversation?->ticket_id) {
            $ticketId = $message->conversation->ticket_id;
            $message->update(['ticket_id' => $ticketId]);
        }

        if (! $ticketId && $message->reply_to_message_id) {
            $parent = Message::find($message->reply_to_message_id);
            if ($parent && $parent->ticket_id) {
                $ticketId = $parent->ticket_id;
                $message->update(['ticket_id' => $ticketId]);
            }
        }

        if (! $ticketId) {
            $conversation = $message->conversation;
            if ($conversation && $conversation->user_id) {
                $ticketId = Message::where('conversation_id', $conversation->id)
                    ->whereNotNull('ticket_id')
                    ->latest('id')
                    ->value('ticket_id');

                if (! $ticketId) {
                    $ticketId = Ticket::where('user_id', $conversation->user_id)
                        ->whereIn('status', ['open', 'on_proses', 'pending', 'need_revision'])
                        ->latest('updated_at')
                        ->value('id');
                }

                if (! $ticketId) {
                    $ticketId = Ticket::where('user_id', $conversation->user_id)
                        ->latest('id')
                        ->value('id');
                }

                if ($ticketId) {
                    $message->update(['ticket_id' => $ticketId]);
                }
            }
        }

        return $ticketId ? Ticket::find($ticketId) : null;
    }

    /**
     * Create a reply log that already knows its chat message, so the timeline
     * event fires once after files and the link are committed.
     */
    public static function createReplyLog(Ticket $ticket, ?Admin $admin, string $body): TicketLog
    {
        return TicketLog::createQuietly([
            'ticket_id' => $ticket->id,
            'admin_id' => $admin?->id,
            'aksi' => 'balasan',
            'catatan' => $body,
            'timestamp' => now(),
        ]);
    }

    /**
     * Run a broker emit after the surrounding transaction commits (immediately
     * when there is no transaction). Broker failures never undo stored data.
     */
    public static function afterCommit(callable $callback): void
    {
        $run = function () use ($callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                Log::warning('Realtime emit failed: '.$e->getMessage());
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($run);

            return;
        }

        $run();
    }

    public static function emitChatMessage(Message $message): void
    {
        broadcast(new ChatMessageSent($message))->toOthers();
    }
}
