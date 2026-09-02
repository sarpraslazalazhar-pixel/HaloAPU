<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Models\Admin;
use Illuminate\Auth\Access\HandlesAuthorization;

class ConversationPolicy
{
    use HandlesAuthorization;

    public function view(mixed $user, Conversation $conversation): bool
    {
        if ($user instanceof User) {
            if ($conversation->type === 'public_global') {
                return true;
            }
            if ($conversation->type === 'user_support' || $conversation->type === 'user_admin_direct') {
                return (int) $conversation->user_id === (int) $user->id;
            }
            return false;
        }

        if ($user instanceof Admin) {
            if ($conversation->type === 'public_global' || $conversation->type === 'user_support') {
                return true;
            }
            if ($conversation->type === 'admin_direct') {
                return (int) $conversation->admin_one_id === (int) $user->id || (int) $conversation->admin_two_id === (int) $user->id;
            }
            if ($conversation->type === 'user_admin_direct') {
                return (int) $conversation->admin_one_id === (int) $user->id;
            }
            return true;
        }

        return false;
    }

    public function sendMessage(mixed $user, Conversation $conversation): bool
    {
        return $this->view($user, conversation: $conversation);
    }
}
