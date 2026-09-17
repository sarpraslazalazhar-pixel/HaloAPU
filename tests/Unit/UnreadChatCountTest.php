<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\User;
use App\Models\Admin;
use App\Models\MessageRead;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UnreadChatCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_guest_returns_zero_and_does_not_cache(): void
    {
        $middleware = new class extends HandleInertiaRequests {
            public function testGetUnreadChatCount(Request $request): int
            {
                return $this->getUnreadChatCount($request);
            }
        };

        $request = Request::create('/');
        $count = $middleware->testGetUnreadChatCount($request);

        $this->assertEquals(0, $count);
        $this->assertFalse(Cache::has('unread_chat_admin_'));
        $this->assertFalse(Cache::has('unread_chat_user_'));
    }

    public function test_admin_and_user_have_isolated_cache_keys(): void
    {
        $admin = new Admin();
        $admin->id = 1;

        $user = new User();
        $user->id = 1;

        Cache::put("unread_chat_admin_1", 7, 30);
        Cache::put("unread_chat_user_1", 3, 30);

        $middleware = new class extends HandleInertiaRequests {
            public function testGetUnreadChatCount(Request $request): int
            {
                return $this->getUnreadChatCount($request);
            }
        };

        $adminRequest = Request::create('/');
        $adminRequest->setUserResolver(function ($guard = null) use ($admin) {
            return $guard === 'admin' ? $admin : null;
        });

        $userRequest = Request::create('/');
        $userRequest->setUserResolver(function ($guard = null) use ($user) {
            return $guard === 'web' ? $user : null;
        });

        $this->assertEquals(7, $middleware->testGetUnreadChatCount($adminRequest));
        $this->assertEquals(3, $middleware->testGetUnreadChatCount($userRequest));
    }

    public function test_message_read_created_clears_user_cache(): void
    {
        Cache::put("unread_chat_user_5", 10, 30);
        $this->assertTrue(Cache::has("unread_chat_user_5"));

        // Creating MessageRead triggers booted event
        $read = new MessageRead([
            'message_id' => 100,
            'user_type' => User::class,
            'user_id' => 5,
        ]);
        // Simulate save / model created event
        event('eloquent.created: ' . MessageRead::class, $read);

        $this->assertFalse(Cache::has("unread_chat_user_5"));
    }

    public function test_message_read_created_clears_admin_cache(): void
    {
        Cache::put("unread_chat_admin_5", 10, 30);
        $this->assertTrue(Cache::has("unread_chat_admin_5"));

        $read = new MessageRead([
            'message_id' => 100,
            'user_type' => Admin::class,
            'user_id' => 5,
        ]);
        event('eloquent.created: ' . MessageRead::class, $read);

        $this->assertFalse(Cache::has("unread_chat_admin_5"));
    }
}
