<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Single write path for account activation. Deactivation keeps the row (and
 * every ticket/chat/message it owns) but revokes every way back in: sessions
 * are versioned, remember-me and push tokens are cleared, and API tokens die.
 */
class UserAccountStatusService
{
    public static function setActive(User $user, bool $isActive): User
    {
        return DB::transaction(function () use ($user, $isActive) {
            /** @var User $locked */
            $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ((bool) $locked->is_active === $isActive) {
                return $locked;
            }

            $locked->is_active = $isActive;

            if (! $isActive) {
                $locked->session_version = (int) $locked->session_version + 1;
                $locked->remember_token = null;
                $locked->fcm_token = null;
                $locked->save();
                $locked->tokens()->delete();
            } else {
                $locked->save();
            }

            $user->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
