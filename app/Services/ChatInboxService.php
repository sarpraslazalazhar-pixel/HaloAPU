<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;

/**
 * One inbox for both clients: the web chat page and the mobile API render the
 * exact same list, so a room started on either side shows up on the other.
 */
class ChatInboxService
{
    /**
     * Every conversation this actor participates in, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function items(User|Admin $actor, int $limit = 50): array
    {
        $isAdmin = $actor instanceof Admin;

        $conversations = Conversation::with([
            'user:id,name,username,email,avatar_path,last_seen_at',
            'adminOne:id,name,username,email,avatar_path,last_seen_at',
            'adminTwo:id,name,username,email,avatar_path,last_seen_at',
            'ticket:id,user_id,unit_id,sub_unit_id,form_data,status,priority,assigned_admin_id,created_by_admin_id',
            'ticket.subUnit:id,nama_layanan',
            'ticket.user:id,name,username,avatar_path',
            'ticket.assignedAdmin:id,name,username,avatar_path',
            'latestMessage.sender',
            'latestMessage.attachments',
        ])->where(function ($base) use ($actor, $isAdmin) {
            $base->where('type', 'public_global');

            if ($isAdmin) {
                $base->orWhere(fn ($q) => $q->where('type', 'admin_bot_reminder')->where('admin_one_id', $actor->id))
                    ->orWhere(fn ($q) => $q->where('type', 'admin_direct')
                        ->where(fn ($q2) => $q2->where('admin_one_id', $actor->id)->orWhere('admin_two_id', $actor->id)))
                    ->orWhere(fn ($q) => $q->where('type', 'user_admin_direct')->where('admin_one_id', $actor->id))
                    ->orWhere(fn ($q) => $q->where('type', 'ticket')
                        ->whereHas('ticket', fn ($tq) => $tq->accessibleBy($actor)));
            } else {
                $base->orWhere(fn ($q) => $q->where('type', 'user_admin_direct')->where('user_id', $actor->id))
                    ->orWhere(fn ($q) => $q->where('type', 'ticket')
                        ->whereHas('ticket', fn ($tq) => $tq->where('user_id', $actor->id)));
            }
        })
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get();

        return $conversations->map(fn (Conversation $conv) => self::present($conv, $actor, $isAdmin))->all();
    }

    /** @return array<string, mixed> */
    public static function present(Conversation $conv, User|Admin $actor, bool $isAdmin): array
    {
        $latest = $conv->latestMessage;
        $ticket = $conv->ticket ?? ($latest?->ticket_id ? Ticket::find($latest->ticket_id) : null);

        // The peer from this actor's point of view.
        $peer = null;
        $isBot = false;

        if ($conv->type === 'ticket') {
            $title = $ticket ? 'Tiket #' . $ticket->formatted_id : 'Diskusi Tiket';
            $subtitle = $ticket ? ($ticket->judul ?: 'Diskusi Tiket') : 'Diskusi Tiket';
            $peer = $isAdmin ? $conv->user : $ticket?->assignedAdmin;
        } elseif ($conv->type === 'admin_bot_reminder') {
            $title = 'Bot Pengingat Halo APU';
            $subtitle = 'Asisten Pengingat Otomatis';
            $isBot = true;
        } elseif ($conv->type === 'public_global') {
            $title = 'Forum Bantuan Halo APU';
            $subtitle = 'Grup Publik';
        } elseif ($conv->type === 'user_admin_direct') {
            $peer = $isAdmin ? $conv->user : $conv->adminOne;
            $title = $peer ? ($peer->name ?? $peer->username) : 'Pengguna';
            $subtitle = $isAdmin ? 'User (Privat)' : 'Admin';
        } else {
            $peer = (int) $conv->admin_one_id === (int) $actor->id ? $conv->adminTwo : $conv->adminOne;
            $title = $peer ? ($peer->name ?? $peer->username) : 'Admin';
            $subtitle = 'Admin';
        }

        $isLastMessageRead = $latest
            ? $latest->reads()->where(fn ($rq) => $rq->where('user_id', '!=', $latest->sender_id)
                ->orWhere('user_type', '!=', $latest->sender_type))->exists()
            : false;

        return [
            'id' => $conv->id,
            'type' => $conv->type,
            'is_bot' => $isBot,
            'title' => $title,
            'subtitle' => $subtitle,
            'user' => $peer ? [
                'id' => $peer->id,
                'name' => $peer->name ?? $peer->username,
                'avatar' => $peer->avatar_path ? '/storage/' . $peer->avatar_path : null,
                'last_seen_at' => $peer->last_seen_at?->toIso8601String(),
            ] : null,
            'ticket_id' => $conv->ticket_id,
            'ticket' => $ticket ? [
                'id' => $ticket->id,
                'formatted_id' => $ticket->formatted_id,
                'judul' => $ticket->judul,
                'status' => $ticket->status,
                // Who filed it and who is answering it.
                'requester' => $ticket->user?->name ?? $ticket->user?->username,
                'handler' => $ticket->assignedAdmin
                    ? ($ticket->assignedAdmin->name ?? $ticket->assignedAdmin->username)
                    : null,
            ] : null,
            'last_message' => $latest
                ? ($latest->body ?: ($latest->attachments->isNotEmpty() ? '[Lampiran]' : '[Pesan]'))
                : 'Belum ada pesan',
            'last_message_at' => ($conv->last_message_at ?? $conv->updated_at)?->toIso8601String(),
            'last_sender_id' => $latest?->sender_id,
            'last_sender_type' => $latest?->sender_type,
            'is_last_message_read' => $isLastMessageRead,
            'unread_count' => self::unreadQuery($conv, $actor, $isAdmin)->count(),
        ];
    }

    public static function unreadQuery(Conversation $conversation, User|Admin $actor, bool $isAdmin)
    {
        $type = $isAdmin ? Admin::class : User::class;

        return Message::where('conversation_id', $conversation->id)
            ->where(fn ($q) => $q->where('sender_type', '!=', $type)->orWhere('sender_id', '!=', $actor->id))
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_type', $type)->where('user_id', $actor->id));
    }
}
