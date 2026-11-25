<?php

namespace App\Services;

use App\Events\ChatMessageEdited;
use App\Events\ChatMessageSent;
use App\Events\TicketMessageEdited;
use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\User;
use App\Notifications\DirectChatMessageNotification;
use App\Notifications\TicketCommentPushNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
     * Send reply notifications to the admins who can actually act on the ticket
     * and to the ticket requester when an admin replies.
     * Never notifies the sender; recipients are deduplicated across sources.
     */
    public static function notifyReplyRecipients(Ticket $ticket, ?Admin $sender, string $message): void
    {
        $ticket->loadMissing(['user', 'assignedAdmin', 'createdByAdmin', 'subUnit.unit']);

        $recipients = collect();

        if ($sender === null) {
            $recipients = $recipients->merge(self::serviceTeamAdmins($ticket));
        } elseif ($sender->id !== $ticket->assigned_admin_id) {
            $recipients = $recipients->merge(self::serviceTeamAdmins($ticket));
        }

        if ($ticket->assignedAdmin) {
            $recipients->push($ticket->assignedAdmin);
        }

        if ($ticket->createdByAdmin) {
            $recipients->push($ticket->createdByAdmin);
        }

        $recipients = $recipients
            ->filter(fn ($admin) => $admin && ($sender === null || (int) $admin->id !== (int) $sender->id))
            ->unique('id');

        $senderName = $sender ? ($sender->name ?? $sender->username) : ($ticket->user?->name ?? $ticket->user?->username ?? 'Pengguna');
        $adminUrl = route('admin.tiket.show', $ticket->id);

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new TicketCommentPushNotification($ticket, $senderName, $message, $adminUrl));
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi balasan tiket #{$ticket->id} ke admin #{$recipient->id}: ".$e->getMessage());
            }
        }

        if ($sender !== null && $ticket->user) {
            try {
                $ticket->user->notify(new TicketCommentPushNotification(
                    $ticket,
                    $senderName,
                    $message,
                    url("/tiket/{$ticket->id}")
                ));
            } catch (\Throwable $e) {
                Log::error("Gagal mengirim notifikasi balasan tiket #{$ticket->id} ke user #{$ticket->user->id}: ".$e->getMessage());
            }
        }
    }

    /**
     * Send database + FCM notifications to chat recipients after commit.
     */
    public static function notifyChatMessageRecipients(Message $message): void
    {
        $message->loadMissing([
            'conversation.ticket',
            'conversation.user',
            'conversation.adminOne',
            'conversation.adminTwo',
            'ticket',
            'sender',
            'attachments',
        ]);

        $conversation = $message->conversation;
        if (! $conversation) {
            return;
        }

        $body = trim((string) $message->body);
        if ($body === '' && $message->attachments->isNotEmpty()) {
            $body = '[Lampiran Chat]';
        }

        if ($body === '') {
            return;
        }

        $isAdminSender = $message->sender_type === Admin::class;
        $senderAdmin = $isAdminSender && $message->sender instanceof Admin ? $message->sender : null;
        $ticket = $message->ticket ?? $conversation->ticket;

        if ($conversation->type === 'ticket' || $ticket !== null) {
            if ($ticket) {
                self::notifyReplyRecipients($ticket, $senderAdmin, $body);
            }

            return;
        }

        if ($conversation->type === 'public_global') {
            return;
        }

        $senderName = $message->sender?->name ?? $message->sender?->username ?? 'Pengguna';

        if ($isAdminSender) {
            if ($conversation->user) {
                try {
                    $conversation->user->notify(new DirectChatMessageNotification(
                        $conversation,
                        $senderName,
                        $body,
                        url('/chat')
                    ));
                } catch (\Throwable $e) {
                    Log::warning("Gagal mengirim notif direct chat ke user #{$conversation->user->id}: ".$e->getMessage());
                }
            }

            $adminTargets = collect([$conversation->adminOne, $conversation->adminTwo])
                ->filter(fn ($adm) => $adm instanceof Admin && (int) $adm->id !== (int) ($senderAdmin?->id ?? 0))
                ->unique('id');

            foreach ($adminTargets as $targetAdmin) {
                try {
                    $targetAdmin->notify(new DirectChatMessageNotification(
                        $conversation,
                        $senderName,
                        $body,
                        url('/admin/chat')
                    ));
                } catch (\Throwable $e) {
                    Log::warning("Gagal mengirim notif direct chat ke admin #{$targetAdmin->id}: ".$e->getMessage());
                }
            }

            return;
        }

        $adminTargets = collect([$conversation->adminOne, $conversation->adminTwo])
            ->filter(fn ($adm) => $adm instanceof Admin)
            ->unique('id');

        if ($adminTargets->isEmpty() && $conversation->type === 'user_support') {
            $adminTargets = Admin::query()->get();
        }

        foreach ($adminTargets as $targetAdmin) {
            try {
                $targetAdmin->notify(new DirectChatMessageNotification(
                    $conversation,
                    $senderName,
                    $body,
                    url('/admin/chat')
                ));
            } catch (\Throwable $e) {
                Log::warning("Gagal mengirim notif direct chat ke admin #{$targetAdmin->id}: ".$e->getMessage());
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

    public const CLOSED_TICKET_STATUSES = ['solve', 'selesai', 'reject', 'dibatalkan'];

    public static function isTicketClosed(Ticket $ticket): bool
    {
        return in_array(strtolower((string) $ticket->status), self::CLOSED_TICKET_STATUSES, true);
    }

    /**
     * Edit a ticket reply (`aksi = balasan`) and keep its mirrored chat message
     * in sync inside one transaction, then broadcast to both channels.
     *
     * @throws \InvalidArgumentException
     */
    public static function editTicketLog(Ticket $ticket, TicketLog $log, string $newBody): TicketLog
    {
        $trimmed = trim($newBody);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Komentar tidak boleh kosong.');
        }

        [$updatedLog, $updatedMessage] = DB::transaction(function () use ($ticket, $log, $trimmed) {
            /** @var TicketLog $locked */
            $locked = TicketLog::lockForUpdate()->findOrFail($log->id);

            if ((int) $locked->ticket_id !== (int) $ticket->id) {
                throw new \InvalidArgumentException('Komentar tidak ditemukan pada tiket ini.');
            }

            if (strtolower((string) $locked->aksi) !== 'balasan') {
                throw new \InvalidArgumentException('Log sistem tidak dapat diedit.');
            }

            /** @var Message|null $message */
            $message = Message::withTrashed()
                ->where('ticket_log_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($message && $message->trashed()) {
                throw new \InvalidArgumentException('Pesan yang sudah dihapus tidak dapat diedit.');
            }

            $now = now();

            $locked->update([
                'catatan' => $trimmed,
                'is_edited' => true,
                'edited_at' => $now,
            ]);

            if ($message) {
                $message->update([
                    'body' => $trimmed,
                    'is_edited' => true,
                    'edited_at' => $now,
                ]);
            }

            return [
                $locked->fresh(['admin', 'attachments']),
                $message?->fresh(),
            ];
        });

        self::afterCommit(function () use ($updatedLog, $updatedMessage): void {
            self::emitTicketMessageEdited($updatedLog);

            if ($updatedMessage) {
                self::emitChatMessageEdited($updatedMessage);
            }
        });

        return $updatedLog;
    }

    /**
     * Mirror a chat message edit back onto its linked `ticket_logs` row and
     * broadcast the timeline edit to ticket watchers.
     */
    public static function syncMessageEditToTicketLog(Message $message): ?TicketLog
    {
        if (! $message->ticket_log_id) {
            return null;
        }

        $log = TicketLog::find($message->ticket_log_id);
        if (! $log || strtolower((string) $log->aksi) !== 'balasan') {
            return null;
        }

        $log->update([
            'catatan' => $message->body,
            'is_edited' => true,
            'edited_at' => $message->edited_at ?? now(),
        ]);

        $fresh = $log->fresh(['admin', 'attachments']);

        self::afterCommit(fn () => self::emitTicketMessageEdited($fresh));

        return $fresh;
    }

    /**
     * Validate and apply a chat message edit across web/API controllers,
     * keeping linked ticket logs in sync.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     * @throws \InvalidArgumentException
     */
    public static function editChatMessage(Message $message, object $actor, string $newBody): Message
    {
        $actorType = $actor instanceof Admin ? Admin::class : User::class;

        if ($message->sender_type !== $actorType || (int) $message->sender_id !== (int) $actor->id) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Anda tidak berhak mengedit pesan ini.');
        }

        $conversation = $message->conversation;
        if (! $conversation || ! Gate::forUser($actor)->allows('view', $conversation)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Anda tidak berhak mengakses percakapan ini.');
        }

        $trimmed = trim($newBody);
        if ($trimmed === '') {
            throw new \InvalidArgumentException('Pesan tidak boleh kosong.');
        }

        [$freshMessage, $freshLog] = DB::transaction(function () use ($message, $actor, $actorType, $trimmed) {
            // Keep lock order identical to editTicketLog() (`ticket_logs` -> `messages`) to prevent AB-BA deadlocks.
            $lockedLog = $message->ticket_log_id !== null
                ? TicketLog::lockForUpdate()->find($message->ticket_log_id)
                : null;

            /** @var Message $lockedMessage */
            $lockedMessage = Message::withTrashed()->lockForUpdate()->findOrFail($message->id);

            if ($lockedLog === null && $lockedMessage->ticket_log_id !== null) {
                $lockedLog = TicketLog::lockForUpdate()->find($lockedMessage->ticket_log_id);
            }

            if ($lockedMessage->sender_type !== $actorType || (int) $lockedMessage->sender_id !== (int) $actor->id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Anda tidak berhak mengedit pesan ini.');
            }

            $lockedConversation = $lockedMessage->conversation()->first();
            if (! $lockedConversation || ! Gate::forUser($actor)->allows('view', $lockedConversation)) {
                throw new \Illuminate\Auth\Access\AuthorizationException('Anda tidak berhak mengakses percakapan ini.');
            }

            if ($lockedMessage->trashed()) {
                throw new \InvalidArgumentException('Pesan yang sudah dihapus tidak dapat diedit.');
            }

            if ($lockedConversation->type === 'ticket') {
                $ticket = $lockedConversation->ticket()->first();
                if ($ticket && self::isTicketClosed($ticket)) {
                    throw new \InvalidArgumentException('Tiket sudah ditutup, pesan tidak dapat diedit.');
                }
            } else {
                $hasBeenRead = MessageRead::where('message_id', $lockedMessage->id)
                    ->where(fn ($q) => $q->where('user_type', '!=', $actorType)->orWhere('user_id', '!=', $actor->id))
                    ->exists();

                if ($hasBeenRead) {
                    throw new \InvalidArgumentException('Pesan yang sudah dibaca tidak dapat diedit.');
                }
            }

            $now = now();

            $lockedMessage->update([
                'body' => $trimmed,
                'is_edited' => true,
                'edited_at' => $now,
            ]);

            $freshLog = null;
            if ($lockedLog && strtolower((string) $lockedLog->aksi) === 'balasan') {
                $lockedLog->update([
                    'catatan' => $trimmed,
                    'is_edited' => true,
                    'edited_at' => $now,
                ]);
                $freshLog = $lockedLog->fresh(['admin', 'attachments']);
            }

            return [$lockedMessage->fresh(), $freshLog];
        });

        self::afterCommit(function () use ($freshMessage, $freshLog): void {
            self::emitChatMessageEdited($freshMessage);

            if ($freshLog) {
                self::emitTicketMessageEdited($freshLog);
            }
        });

        return $freshMessage;
    }

    public static function emitChatMessage(Message $message): void
    {
        broadcast(new ChatMessageSent($message))->toOthers();
    }

    public static function emitChatMessageEdited(Message $message): void
    {
        broadcast(new ChatMessageEdited($message))->toOthers();
    }

    public static function emitTicketMessageEdited(TicketLog $log): void
    {
        broadcast(new TicketMessageEdited($log));
    }
}
