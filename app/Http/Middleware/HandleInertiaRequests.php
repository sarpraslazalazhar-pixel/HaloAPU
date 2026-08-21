<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\User;
use App\Models\Admin;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $webUser = $request->user('web');
        if ($webUser && (!$webUser->last_seen_at || $webUser->last_seen_at->lt(now()->subMinutes(2)))) {
            $webUser->updateQuietly(['last_seen_at' => now()]);
        }

        $adminUser = $request->user('admin');
        if ($adminUser && (!$adminUser->last_seen_at || $adminUser->last_seen_at->lt(now()->subMinutes(2)))) {
            $adminUser->updateQuietly(['last_seen_at' => now()]);
        }

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $webUser,
                'admin' => $adminUser,
                'permissions' => $request->user('admin') 
                    ? ($request->user('admin')->hasRole('Super Admin') 
                        ? \Spatie\Permission\Models\Permission::pluck('name') 
                        : $request->user('admin')->getAllPermissions()->pluck('name')) 
                    : [],
            ],
            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'appConfig' => fn () => [
                'nama_sistem' => \App\Models\SystemConfig::getValue('nama_sistem', 'Halo APU'),
                'logo_path' => \App\Models\SystemConfig::getValue('logo_path'),
                'banner_path' => \App\Models\SystemConfig::getValue('banner_path'),
                'favicon_path' => \App\Models\SystemConfig::getValue('favicon_path'),
                'notification_sound_path' => \App\Models\SystemConfig::getValue('notification_sound_path'),
            ],
            'unread_chat_count' => fn () => $this->getUnreadChatCount($request),
        ]);
    }

    protected function getUnreadChatCount(Request $request): int
    {
        $admin = $request->user('admin');
        if ($admin) {
            return \App\Models\Message::whereHas('conversation', function ($q) use ($admin) {
                $q->where('type', 'public_global')
                  ->orWhere(function ($q2) use ($admin) {
                      $q2->where('type', 'admin_bot_reminder')->where('admin_one_id', $admin->id);
                  })
                  ->orWhere(function ($q2) use ($admin) {
                      $q2->where('type', 'admin_direct')->where(function ($q3) use ($admin) {
                          $q3->where('admin_one_id', $admin->id)->orWhere('admin_two_id', $admin->id);
                      });
                  })
                  ->orWhere(function ($q2) use ($admin) {
                      $q2->where('type', 'user_admin_direct')->where('admin_one_id', $admin->id);
                  });
            })
            ->where(function ($sq) use ($admin) {
                $sq->where('sender_type', '!=', \App\Models\Admin::class)
                   ->orWhere('sender_id', '!=', $admin->id);
            })
            ->whereDoesntHave('reads', function ($rq) use ($admin) {
                $rq->where('user_type', \App\Models\Admin::class)->where('user_id', $admin->id);
            })
            ->count();
        }

        $user = $request->user('web');
        if ($user) {
            return \App\Models\Message::whereHas('conversation', function ($q) use ($user) {
                $q->where('type', 'public_global')
                  ->orWhere('user_id', $user->id);
            })
            ->where('sender_type', '!=', \App\Models\User::class)
            ->whereDoesntHave('reads', function ($rq) use ($user) {
                $rq->where('user_type', \App\Models\User::class)->where('user_id', $user->id);
            })
            ->count();
        }

        return 0;
    }
}
