<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user instanceof \App\Models\User && (int) $user->id === (int) $id;
}, ['guards' => ['web', 'admin', 'sanctum']]);

Broadcast::channel('App.Models.Admin.{id}', function ($user, $id) {
    return $user instanceof \App\Models\Admin && (int) $user->id === (int) $id;
}, ['guards' => ['admin', 'web', 'sanctum']]);

Broadcast::channel('chat.public_global', function ($user) {
    return $user !== null;
}, ['guards' => ['web', 'admin', 'sanctum']]);

Broadcast::channel('chat.conversation.{conversationId}', function ($user, $conversationId) {
    $conversation = \App\Models\Conversation::find($conversationId);
    if (!$conversation) {
        return false;
    }

    if ($conversation->type === 'public_global') {
        return true;
    }

    if ($user instanceof \App\Models\User) {
        return (int) $conversation->user_id === (int) $user->id;
    }

    if ($user instanceof \App\Models\Admin) {
        return true;
    }

    return false;
}, ['guards' => ['web', 'admin', 'sanctum']]);

// Ticket timeline. Only the ticket's owner and admins who may act on it.
Broadcast::channel('ticket.{ticketId}', function ($user, $ticketId) {
    $ticket = \App\Models\Ticket::find($ticketId);

    if (!$ticket) {
        return false;
    }

    if ($user instanceof \App\Models\User) {
        return (int) $ticket->user_id === (int) $user->id;
    }

    if ($user instanceof \App\Models\Admin) {
        return $ticket->isAccessibleBy($user);
    }

    return false;
}, ['guards' => ['web', 'admin', 'sanctum']]);

Broadcast::channel('chat.presence', function ($user) {
    if (!$user) {
        return false;
    }

    return [
        'id' => (int) $user->id,
        'name' => $user->name ?? $user->username,
        'type' => $user instanceof \App\Models\Admin ? 'admin' : 'user',
        'avatar' => !empty($user->avatar_path) ? '/storage/' . $user->avatar_path : null,
    ];
}, ['guards' => ['web', 'admin', 'sanctum']]);


