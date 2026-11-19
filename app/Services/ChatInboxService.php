<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageRead;
use App\Models\Ticket;
use App\Models\TicketLog;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Shared inbox & room sync for Web Chat and Mobile API.
 *
 * - User Web Chat (`/chat`): per-ticket rooms for every ticket the user submitted.
 * - Admin Web Chat (`/admin/chat`): grouped per-requester (Users who submitted
 *   tickets, other Admins who submitted tickets, and this Admin's own submitted
 *   tickets), with per-ticket room switching inside each requester.
 */
class ChatInboxService
{
    /**
     * Conversation identity columns are foreign keys, so a ticket row carrying a
     * user/admin id that no longer exists (imported or deleted out-of-band) must
     * not be copied verbatim — that insert fails with a FK violation.
     *
     * @param  array<int, int>  $validUserIds
     * @param  array<int, int>  $validAdminIds
     * @return array{user_id: ?int, admin_one_id: ?int, admin_two_id: ?int}
     */
    private static function roomIdentity(Ticket $ticket, array $validUserIds, array $validAdminIds): array
    {
        $pick = fn ($id, array $valid): ?int => $id !== null && in_array((int) $id, $valid, true) ? (int) $id : null;

        return [
            'user_id' => $pick($ticket->user_id, $validUserIds),
            'admin_one_id' => $pick($ticket->assigned_admin_id, $validAdminIds),
            'admin_two_id' => $pick($ticket->created_by_admin_id, $validAdminIds),
        ];
    }

    /**
     * Ensure a ticket owns a `type = 'ticket'` conversation and backfill any
     * existing `balasan` timeline logs if the room was never populated.
     */
    public static function ensureTicketConversation(Ticket $ticket): Conversation
    {
        $validUserIds = $ticket->user_id ? User::whereKey($ticket->user_id)->pluck('id')->all() : [];
        $validAdminIds = Admin::whereIn('id', array_filter([$ticket->assigned_admin_id, $ticket->created_by_admin_id]))
            ->pluck('id')
            ->all();
        $identity = self::roomIdentity($ticket, $validUserIds, $validAdminIds);

        $conversation = Conversation::firstOrCreate(
            ['type' => 'ticket', 'ticket_id' => $ticket->id],
            $identity + ['last_message_at' => $ticket->updated_at ?? $ticket->created_at ?? now()]
        );

        $updates = [];

        if ($conversation->user_id !== $identity['user_id']) {
            $updates['user_id'] = $identity['user_id'];
        }
        if ($conversation->admin_one_id !== $identity['admin_one_id']) {
            $updates['admin_one_id'] = $identity['admin_one_id'];
        }
        if ($conversation->admin_two_id !== $identity['admin_two_id']) {
            $updates['admin_two_id'] = $identity['admin_two_id'];
        }
        if (!empty($updates)) {
            $conversation->updateQuietly($updates);
        }

        self::backfillTicketLogsIfEmpty($conversation, $ticket);

        return $conversation;
    }

    /**
     * Bulk-ensure `type = 'ticket'` conversations for a collection of tickets.
     *
     * @param Collection<int, Ticket> $tickets
     */
    public static function syncTickets(Collection $tickets): void
    {
        if ($tickets->isEmpty()) {
            return;
        }

        $ticketIds = $tickets->pluck('id')->all();
        $existing = Conversation::where('type', 'ticket')
            ->whereIn('ticket_id', $ticketIds)
            ->get()
            ->keyBy('ticket_id');

        // Conversation identity columns are FKs: resolve only ids that still exist.
        $validUserIds = User::whereIn('id', $tickets->pluck('user_id')->filter()->unique()->all())
            ->pluck('id')
            ->all();
        $validAdminIds = Admin::whereIn('id', $tickets->pluck('assigned_admin_id')->filter()->unique()->all())
            ->orWhereIn('id', $tickets->pluck('created_by_admin_id')->filter()->unique()->all())
            ->pluck('id')
            ->all();

        $conversationsByTicket = [];

        foreach ($tickets as $ticket) {
            $identity = self::roomIdentity($ticket, $validUserIds, $validAdminIds);

            /** @var Conversation|null $conv */
            $conv = $existing->get($ticket->id);

            if (!$conv) {
                $conv = Conversation::firstOrCreate(
                    ['type' => 'ticket', 'ticket_id' => $ticket->id],
                    $identity + ['last_message_at' => $ticket->updated_at ?? $ticket->created_at ?? now()]
                );
            } else {
                $changes = [];

                foreach (['user_id', 'admin_one_id', 'admin_two_id'] as $column) {
                    if ($conv->{$column} !== $identity[$column]) {
                        $changes[$column] = $identity[$column];
                    }
                }

                if ($changes !== []) {
                    $conv->updateQuietly($changes);
                }
            }

            $conversationsByTicket[$ticket->id] = $conv;
        }

        // Backfill timeline replies only for rooms that have zero messages (including soft-deleted).
        $convIds = collect($conversationsByTicket)->pluck('id')->all();
        $populatedConvIds = Message::withTrashed()
            ->whereIn('conversation_id', $convIds)
            ->distinct()
            ->pluck('conversation_id')
            ->flip();

        $emptyTicketIds = [];
        foreach ($conversationsByTicket as $tId => $conv) {
            if (!$populatedConvIds->has($conv->id)) {
                $emptyTicketIds[] = $tId;
            }
        }

        if (empty($emptyTicketIds)) {
            return;
        }

        $logsByTicket = TicketLog::with('attachments')
            ->whereIn('ticket_id', $emptyTicketIds)
            ->where('aksi', 'balasan')
            ->orderBy('timestamp')
            ->orderBy('id')
            ->get()
            ->groupBy('ticket_id');

        if ($logsByTicket->isEmpty()) {
            return;
        }

        $ticketsKeyed = $tickets->keyBy('id');
        foreach ($logsByTicket as $tId => $logs) {
            $ticket = $ticketsKeyed->get($tId);
            $conv = $conversationsByTicket[$tId] ?? null;
            if ($ticket && $conv) {
                self::importLogsIntoConversation($conv, $ticket, $logs);
            }
        }
    }

    protected static function backfillTicketLogsIfEmpty(Conversation $conversation, Ticket $ticket): void
    {
        $hasAnyMessage = Message::withTrashed()
            ->where('conversation_id', $conversation->id)
            ->exists();

        if ($hasAnyMessage) {
            return;
        }

        $logs = TicketLog::with('attachments')
            ->where('ticket_id', $ticket->id)
            ->where('aksi', 'balasan')
            ->orderBy('timestamp')
            ->orderBy('id')
            ->get();

        if ($logs->isEmpty()) {
            return;
        }

        self::importLogsIntoConversation($conversation, $ticket, $logs);
    }

    /**
     * @param Collection<int, TicketLog> $logs
     */
    protected static function importLogsIntoConversation(Conversation $conversation, Ticket $ticket, Collection $logs): void
    {
        $latestTimestamp = $conversation->last_message_at;

        foreach ($logs as $log) {
            $body = trim((string) $log->catatan);
            if ($body === '' && $log->attachments && $log->attachments->isNotEmpty()) {
                $body = '[Lampiran Tiket]';
            }

            if ($log->admin_id) {
                $senderType = Admin::class;
                $senderId = (int) $log->admin_id;
            } elseif ($ticket->user_id) {
                $senderType = User::class;
                $senderId = (int) $ticket->user_id;
            } elseif ($ticket->created_by_admin_id) {
                $senderType = Admin::class;
                $senderId = (int) $ticket->created_by_admin_id;
            } else {
                continue;
            }

            $ts = $log->timestamp ?? $ticket->created_at ?? now();

            // ticket_log_id is the identity: a replayed import must not copy the
            // same reply twice, and a soft-deleted message stays deleted.
            /** @var Message $msg */
            $msg = Message::withTrashed()->firstOrCreate(
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

            self::copyLogAttachments($log, $msg);

            if ($msg->wasRecentlyCreated) {
                MessageRead::firstOrCreate([
                    'message_id' => $msg->id,
                    'user_type' => $senderType,
                    'user_id' => $senderId,
                ], ['read_at' => $ts]);
            }

            if ($latestTimestamp === null || $ts->gt($latestTimestamp)) {
                $latestTimestamp = $ts;
            }
        }

        if ($latestTimestamp) {
            $conversation->updateQuietly(['last_message_at' => $latestTimestamp]);
        }
    }

    /**
     * Mirror ticket attachment metadata onto a chat message without copying bytes.
     */
    public static function copyLogAttachments(TicketLog $log, Message $message): void
    {
        if (! $log->attachments || $log->attachments->isEmpty()) {
            return;
        }

        foreach ($log->attachments as $att) {
            ChatAttachment::firstOrCreate(
                ['message_id' => $message->id, 'file_path' => $att->file_path],
                [
                    'file_name' => $att->original_name ?: basename($att->file_path),
                    'file_type' => $att->mime_type ?: 'application/octet-stream',
                    'file_size' => (int) ($att->file_size ?: 0),
                ]
            );
        }
    }

    /**
     * User Web Chat (`/chat`): one room per ticket submitted by this user.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function userItems(User $user, int $limit = 100): array
    {
        $tickets = Ticket::ownedByUser($user)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        self::syncTickets($tickets);

        $conversations = Conversation::with([
            'user:id,name,username,email,avatar_path,last_seen_at',
            'ticket:id,user_id,unit_id,sub_unit_id,form_data,status,priority,assigned_admin_id,created_by_admin_id,created_at,updated_at',
            'ticket.subUnit:id,unit_id,nama_layanan',
            'ticket.subUnit.unit:id,nama_unit',
            'ticket.user:id,name,username,avatar_path',
            'ticket.createdByAdmin:id,name,username,avatar_path',
            'ticket.assignedAdmin:id,name,username,avatar_path,last_seen_at',
            'latestMessage.sender',
            'latestMessage.attachments',
            'latestMessage.reads',
        ])
            ->where('type', 'ticket')
            ->whereHas('ticket', fn ($tq) => $tq->ownedByUser($user))
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $unreadByConv = self::unreadCountsMap($conversations->pluck('id')->all(), $user, false);

        return $conversations
            ->map(fn (Conversation $conv) => self::present($conv, $user, false, $unreadByConv[$conv->id] ?? 0))
            ->all();
    }

    /**
     * Admin Web Chat (`/admin/chat`): grouped per-requester (Users who submitted
     * accessible tickets, other Admins who submitted accessible tickets, and
     * tickets submitted by this Admin themselves).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function adminGroupedItems(Admin $admin, ?string $search = null, bool $unreadOnly = false): array
    {
        $tickets = Ticket::accessibleBy($admin)
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        self::syncTickets($tickets);

        $query = Conversation::with([
            'ticket:id,user_id,created_by_admin_id,assigned_admin_id,unit_id,sub_unit_id,form_data,status,priority,created_at,updated_at',
            'ticket.user:id,name,username,email,avatar_path,last_seen_at',
            'ticket.createdByAdmin:id,name,username,email,avatar_path,last_seen_at',
            'ticket.subUnit:id,unit_id,nama_layanan',
            'ticket.subUnit.unit:id,nama_unit',
            'ticket.assignedAdmin:id,name,username,avatar_path,last_seen_at',
            'latestMessage.sender',
            'latestMessage.attachments',
            'latestMessage.reads',
        ])
            ->where('type', 'ticket')
            ->whereHas('ticket', fn ($tq) => $tq->accessibleBy($admin));

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $cleanId = str_replace(['#', '-', ' '], '', $term);
            $query->where(function ($q) use ($term, $cleanId) {
                $q->whereHas('ticket', function ($tq) use ($term, $cleanId) {
                    if ($cleanId !== '') {
                        $tq->where('tickets.id', 'like', "%{$cleanId}%");
                    } else {
                        $tq->whereRaw('1 = 0');
                    }
                    $tq->orWhere('tickets.form_data', 'like', "%{$term}%")
                        ->orWhereHas('user', fn ($uq) => $uq->where('name', 'like', "%{$term}%")
                            ->orWhere('username', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%"))
                        ->orWhereHas('createdByAdmin', fn ($aq) => $aq->where('name', 'like', "%{$term}%")
                            ->orWhere('username', 'like', "%{$term}%")
                            ->orWhere('email', 'like', "%{$term}%"))
                        ->orWhereHas('subUnit', fn ($sq) => $sq->where('nama_layanan', 'like', "%{$term}%"));
                })->orWhereHas('messages', fn ($mq) => $mq->where('body', 'like', "%{$term}%"));
            });
        }

        $conversations = $query
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->get();

        if ($conversations->isEmpty()) {
            return [];
        }

        $unreadByConv = self::unreadCountsMap($conversations->pluck('id')->all(), $admin, true);

        /** @var array<string, array<string, mixed>> $groups */
        $groups = [];

        foreach ($conversations as $conv) {
            $ticket = $conv->ticket;
            if (!$ticket) {
                continue;
            }

            if ($ticket->isSubmittedBy($admin)) {
                $groupKey = 'self_admin:' . $admin->id;
                $requesterType = 'admin';
                $isSelfRequester = true;
                $peer = $admin;
            } elseif ($ticket->created_by_admin_id && $ticket->createdByAdmin) {
                $groupKey = 'admin:' . $ticket->created_by_admin_id;
                $requesterType = 'admin';
                $isSelfRequester = false;
                $peer = $ticket->createdByAdmin;
            } elseif ($ticket->user_id && $ticket->user) {
                $groupKey = 'user:' . $ticket->user_id;
                $requesterType = 'user';
                $isSelfRequester = false;
                $peer = $ticket->user;
            } else {
                continue;
            }

            $latest = $conv->latestMessage;
            $unreadCount = (int) ($unreadByConv[$conv->id] ?? 0);
            $lastMessageAt = ($conv->last_message_at ?? $ticket->updated_at ?? $conv->updated_at)?->toIso8601String();

            $hasMessage = $latest !== null;
            $snippet = $latest
                ? ($latest->body ?: ($latest->attachments->isNotEmpty() ? '[Lampiran]' : '[Pesan]'))
                : 'Belum ada pesan diskusi';

            $isLastRead = $latest
                ? $latest->reads->contains(fn ($r) => (int) $r->user_id !== (int) $latest->sender_id || $r->user_type !== $latest->sender_type)
                : false;

            $roomSummary = [
                'conversation_id' => $conv->id,
                'ticket_id' => $ticket->id,
                'formatted_id' => $ticket->formatted_id,
                'judul' => $ticket->judul,
                'chat_label' => $ticket->chatLabel(),
                'created_at' => $ticket->created_at?->toIso8601String(),
                'nama_layanan' => $ticket->subUnit?->nama_layanan,
                'nama_unit' => $ticket->subUnit?->unit?->nama_unit,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'requester' => $peer->name ?? $peer->username ?? 'Pemohon',
                'handler' => $ticket->assignedAdmin
                    ? ($ticket->assignedAdmin->name ?? $ticket->assignedAdmin->username)
                    : null,
                'unread_count' => $unreadCount,
                'has_message' => $hasMessage,
                'last_message' => $snippet,
                'last_message_at' => $lastMessageAt,
                'last_message_sender_id' => $latest?->sender_id,
                'last_message_sender_type' => $latest?->sender_type,
                'is_last_message_read' => $isLastRead,
            ];

            if (!isset($groups[$groupKey])) {
                $peerName = $peer->name ?? $peer->username ?? 'Pemohon';
                $groups[$groupKey] = [
                    'group_key' => $groupKey,
                    'requester_type' => $requesterType,
                    'is_self_requester' => $isSelfRequester,
                    'peer' => [
                        'id' => $peer->id,
                        'name' => $peerName,
                        'email' => $peer->email ?? '',
                        'avatar' => !empty($peer->avatar_path) ? '/storage/' . $peer->avatar_path : null,
                        'last_seen_at' => $peer->last_seen_at ? $peer->last_seen_at->toIso8601String() : null,
                        'entity_type' => $requesterType,
                    ],
                    'rooms' => [],
                ];
            }

            $groups[$groupKey]['rooms'][] = $roomSummary;
        }

        $result = [];

        foreach ($groups as $groupKey => $group) {
            /** @var array<int, array<string, mixed>> $rooms */
            $rooms = $group['rooms'];

            // Latest activity room (for timestamp & preview).
            usort($rooms, fn ($a, $b) => strcmp((string) ($b['last_message_at'] ?? ''), (string) ($a['last_message_at'] ?? '')));
            $latestRoom = $rooms[0];

            // Primary room opened on click: prefer unread rooms first, then newest activity.
            $sortedForSelection = $rooms;
            usort($sortedForSelection, function ($a, $b) {
                $aUnread = ($a['unread_count'] ?? 0) > 0 ? 1 : 0;
                $bUnread = ($b['unread_count'] ?? 0) > 0 ? 1 : 0;
                if ($aUnread !== $bUnread) {
                    return $bUnread <=> $aUnread;
                }
                return strcmp((string) ($b['last_message_at'] ?? ''), (string) ($a['last_message_at'] ?? ''));
            });
            $primaryRoom = $sortedForSelection[0];

            $totalUnread = array_sum(array_column($rooms, 'unread_count'));
            if ($unreadOnly && $totalUnread === 0) {
                continue;
            }

            $count = count($rooms);
            $peer = $group['peer'];
            $isSelf = (bool) $group['is_self_requester'];
            $reqType = (string) $group['requester_type'];

            $title = $isSelf ? "{$peer['name']} (Tiket Saya)" : $peer['name'];

            if ($isSelf) {
                $subtitle = $count === 1
                    ? "Tiket Saya · {$primaryRoom['chat_label']}"
                    : "Tiket Saya Ajukan · {$count} Tiket";
            } elseif ($reqType === 'admin') {
                $subtitle = $count === 1
                    ? "Admin Pemohon · {$primaryRoom['chat_label']}"
                    : "Admin Pemohon · {$count} Tiket";
            } else {
                $subtitle = $count === 1
                    ? "Pemohon · {$primaryRoom['chat_label']}"
                    : "Pemohon · {$count} Tiket";
            }

            if ($latestRoom['has_message']) {
                $preview = $count > 1
                    ? "[{$latestRoom['chat_label']}] {$latestRoom['last_message']}"
                    : $latestRoom['last_message'];
            } else {
                $preview = "{$latestRoom['chat_label']}: {$latestRoom['judul']}";
            }

            $result[] = [
                'id' => $primaryRoom['conversation_id'],
                'group_key' => $groupKey,
                'is_bot' => false,
                'type' => 'ticket',
                'requester_type' => $reqType,
                'is_self_requester' => $isSelf,
                'user' => $peer,
                'title' => $title,
                'subtitle' => $subtitle,
                'ticket_id' => $primaryRoom['ticket_id'],
                'ticket' => [
                    'id' => $primaryRoom['ticket_id'],
                    'formatted_id' => $primaryRoom['formatted_id'],
                    'judul' => $primaryRoom['judul'],
                    'chat_label' => $primaryRoom['chat_label'],
                    'created_at' => $primaryRoom['created_at'],
                    'nama_layanan' => $primaryRoom['nama_layanan'],
                    'nama_unit' => $primaryRoom['nama_unit'],
                    'status' => $primaryRoom['status'],
                    'priority' => $primaryRoom['priority'],
                    'requester' => $primaryRoom['requester'],
                    'handler' => $primaryRoom['handler'],
                ],
                'tickets_count' => $count,
                'conversation_ids' => array_values(array_column($rooms, 'conversation_id')),
                'ticket_rooms' => $rooms,
                'last_message' => $preview,
                'last_message_at' => $latestRoom['last_message_at'],
                'last_message_sender_id' => $latestRoom['last_message_sender_id'],
                'last_message_sender_type' => $latestRoom['last_message_sender_type'],
                'is_last_message_read' => $latestRoom['is_last_message_read'],
                'unread_count' => $totalUnread,
            ];
        }

        usort($result, fn ($a, $b) => strcmp((string) ($b['last_message_at'] ?? ''), (string) ($a['last_message_at'] ?? '')));

        return $result;
    }

    /**
     * Mobile API inbox (`/api/conversations`): ticket rooms + active direct rooms.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(User|Admin $actor, int $limit = 50): array
    {
        $isAdmin = $actor instanceof Admin;

        if ($isAdmin) {
            self::syncTickets(
                Ticket::accessibleBy($actor)->orderByDesc('updated_at')->limit(100)->get()
            );
        } else {
            self::syncTickets(
                Ticket::ownedByUser($actor)->orderByDesc('updated_at')->limit(100)->get()
            );
        }

        $conversations = Conversation::with([
            'user:id,name,username,email,avatar_path,last_seen_at',
            'adminOne:id,name,username,email,avatar_path,last_seen_at',
            'adminTwo:id,name,username,email,avatar_path,last_seen_at',
            'ticket:id,user_id,unit_id,sub_unit_id,form_data,status,priority,assigned_admin_id,created_by_admin_id,created_at,updated_at',
            'ticket.subUnit:id,unit_id,nama_layanan',
            'ticket.subUnit.unit:id,nama_unit',
            'ticket.user:id,name,username,avatar_path',
            'ticket.createdByAdmin:id,name,username,avatar_path',
            'ticket.assignedAdmin:id,name,username,avatar_path,last_seen_at',
            'latestMessage.sender',
            'latestMessage.attachments',
            'latestMessage.reads',
        ])->where(function ($base) use ($actor, $isAdmin) {
            if ($isAdmin) {
                $base->where(fn ($q) => $q->where('type', 'ticket')
                    ->whereHas('ticket', fn ($tq) => $tq->accessibleBy($actor)))
                    ->orWhere(fn ($q) => $q->where('type', 'user_admin_direct')
                        ->where('admin_one_id', $actor->id)
                        ->whereHas('messages'));
            } else {
                $base->where(fn ($q) => $q->where('type', 'ticket')
                    ->whereHas('ticket', fn ($tq) => $tq->ownedByUser($actor)))
                    ->orWhere(fn ($q) => $q->where('type', 'user_admin_direct')
                        ->where('user_id', $actor->id)
                        ->whereHas('messages'));
            }
        })
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        $unreadByConv = self::unreadCountsMap($conversations->pluck('id')->all(), $actor, $isAdmin);

        return $conversations
            ->map(fn (Conversation $conv) => self::present($conv, $actor, $isAdmin, $unreadByConv[$conv->id] ?? 0))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function present(Conversation $conv, User|Admin $actor, bool $isAdmin, ?int $precomputedUnread = null): array
    {
        $conv->loadMissing([
            'ticket.subUnit.unit',
            'ticket.user',
            'ticket.createdByAdmin',
            'ticket.assignedAdmin',
            'latestMessage.attachments',
            'latestMessage.reads',
        ]);

        $latest = $conv->latestMessage;
        $ticket = $conv->ticket ?? ($latest?->ticket_id ? Ticket::find($latest->ticket_id) : null);

        $peer = null;
        $peerEntityType = 'user';
        $isBot = false;
        $isSelfRequester = false;
        $requesterKey = null;

        if ($conv->type === 'ticket') {
            if ($isAdmin && $actor instanceof Admin) {
                if ($ticket && $ticket->isSubmittedBy($actor)) {
                    $peer = $ticket->assignedAdmin ?? $actor;
                    $peerEntityType = 'admin';
                    $isSelfRequester = true;
                    $requesterKey = 'self_admin:' . $actor->id;
                    $title = ($actor->name ?? $actor->username) . ' (Tiket Saya)';
                    $subtitle = $ticket ? ('Tiket #' . $ticket->formatted_id . ' · ' . ($ticket->judul ?: 'Diskusi Tiket')) : 'Tiket Saya';
                } elseif ($ticket && $ticket->created_by_admin_id && $ticket->createdByAdmin) {
                    $peer = $ticket->createdByAdmin;
                    $peerEntityType = 'admin';
                    $requesterKey = 'admin:' . $ticket->created_by_admin_id;
                    $title = $peer->name ?? $peer->username;
                    $subtitle = 'Admin Pemohon · Tiket #' . $ticket->formatted_id;
                } else {
                    $peer = $ticket?->user ?? $conv->user;
                    $peerEntityType = 'user';
                    $requesterKey = $peer ? ('user:' . $peer->id) : null;
                    $title = $peer ? ($peer->name ?? $peer->username) : ($ticket ? 'Tiket #' . $ticket->formatted_id : 'Pemohon');
                    $subtitle = $ticket ? ('Pemohon · Tiket #' . $ticket->formatted_id) : 'Diskusi Tiket';
                }
            } else {
                $title = $ticket ? $ticket->chatLabel() : 'Diskusi Tiket';
                $subtitle = $ticket ? ($ticket->judul ?: ($ticket->subUnit?->nama_layanan ?? 'Diskusi Tiket')) : 'Diskusi Tiket';
                $peer = $ticket?->assignedAdmin;
                $peerEntityType = 'admin';
            }
        } elseif ($conv->type === 'admin_bot_reminder') {
            $title = 'Bot Pengingat Halo APU';
            $subtitle = 'Asisten Pengingat Otomatis';
            $isBot = true;
        } elseif ($conv->type === 'public_global') {
            $title = 'Forum Bantuan Halo APU';
            $subtitle = 'Grup Publik';
        } elseif ($conv->type === 'user_admin_direct') {
            $peer = $isAdmin ? $conv->user : $conv->adminOne;
            $peerEntityType = $isAdmin ? 'user' : 'admin';
            $title = $peer ? ($peer->name ?? $peer->username) : 'Pengguna';
            $subtitle = $isAdmin ? 'User (Privat)' : 'Admin';
        } else {
            $peer = (int) $conv->admin_one_id === (int) $actor->id ? $conv->adminTwo : $conv->adminOne;
            $peerEntityType = 'admin';
            $title = $peer ? ($peer->name ?? $peer->username) : 'Admin';
            $subtitle = 'Admin';
        }

        $isLastMessageRead = $latest
            ? $latest->reads->contains(fn ($r) => (int) $r->user_id !== (int) $latest->sender_id || $r->user_type !== $latest->sender_type)
            : false;

        $requesterName = null;
        if ($ticket) {
            if ($ticket->created_by_admin_id && $ticket->createdByAdmin) {
                $requesterName = $ticket->createdByAdmin->name ?? $ticket->createdByAdmin->username;
            } elseif ($ticket->user) {
                $requesterName = $ticket->user->name ?? $ticket->user->username;
            }
        }

        return [
            'id' => $conv->id,
            'group_key' => $requesterKey,
            'type' => $conv->type,
            'requester_type' => $peerEntityType,
            'is_self_requester' => $isSelfRequester,
            'is_bot' => $isBot,
            'title' => $title,
            'subtitle' => $subtitle,
            'user' => $peer ? [
                'id' => $peer->id,
                'name' => $peer->name ?? $peer->username,
                'email' => $peer->email ?? '',
                'avatar' => !empty($peer->avatar_path) ? '/storage/' . $peer->avatar_path : null,
                'last_seen_at' => $peer->last_seen_at?->toIso8601String(),
                'entity_type' => $peerEntityType,
            ] : null,
            'ticket_id' => $conv->ticket_id,
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'formatted_id' => $ticket->formatted_id,
                'judul' => $ticket->judul,
                'chat_label' => $ticket->chatLabel(),
                'created_at' => $ticket->created_at?->toIso8601String(),
                'nama_layanan' => $ticket->subUnit?->nama_layanan,
                'nama_unit' => $ticket->subUnit?->unit?->nama_unit,
                'status' => $ticket->status,
                'priority' => $ticket->priority,
                'requester' => $requesterName,
                'handler' => $ticket->assignedAdmin
                    ? ($ticket->assignedAdmin->name ?? $ticket->assignedAdmin->username)
                    : null,
            ] : null,
            'last_message' => $latest
                ? ($latest->body ?: ($latest->attachments->isNotEmpty() ? '[Lampiran]' : '[Pesan]'))
                : 'Belum ada pesan diskusi',
            'last_message_at' => ($conv->last_message_at ?? $ticket?->updated_at ?? $conv->updated_at)?->toIso8601String(),
            'last_sender_id' => $latest?->sender_id,
            'last_sender_type' => $latest?->sender_type,
            'last_message_sender_id' => $latest?->sender_id,
            'last_message_sender_type' => $latest?->sender_type,
            'is_last_message_read' => $isLastMessageRead,
            'unread_count' => $precomputedUnread ?? self::unreadQuery($conv, $actor, $isAdmin)->count(),
        ];
    }

    /**
     * @param array<int, int> $conversationIds
     * @return array<int, int>
     */
    protected static function unreadCountsMap(array $conversationIds, User|Admin $actor, bool $isAdmin): array
    {
        if (empty($conversationIds)) {
            return [];
        }

        $type = $isAdmin ? Admin::class : User::class;

        return Message::whereIn('conversation_id', $conversationIds)
            ->where(fn ($q) => $q->where('sender_type', '!=', $type)->orWhere('sender_id', '!=', $actor->id))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_type', $type)->where('user_id', $actor->id))
            ->selectRaw('conversation_id, COUNT(*) as aggregate')
            ->groupBy('conversation_id')
            ->pluck('aggregate', 'conversation_id')
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    public static function unreadQuery(Conversation $conversation, User|Admin $actor, bool $isAdmin)
    {
        $type = $isAdmin ? Admin::class : User::class;

        return Message::where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->where('sender_type', '!=', $type)->orWhere('sender_id', '!=', $actor->id))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_type', $type)->where('user_id', $actor->id));
    }
}
