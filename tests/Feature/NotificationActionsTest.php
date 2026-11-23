<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use App\Notifications\BrowserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function createNotificationFor(Admin|User $notifiable): DatabaseNotification
    {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => BrowserNotification::class,
            'notifiable_type' => $notifiable->getMorphClass(),
            'notifiable_id' => $notifiable->id,
            'data' => [
                'title' => 'Notifikasi Uji',
                'message' => 'Isi notifikasi uji',
                'url' => '/tiket/1',
                'aksi_url' => '/tiket/1',
            ],
            'read_at' => null,
        ]);
    }

    public function test_admin_can_mark_single_notification_as_read_and_updates_unread_count(): void
    {
        $admin = Admin::factory()->create();
        $first = $this->createNotificationFor($admin);
        $this->createNotificationFor($admin);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 2]);

        $this->actingAs($admin, 'admin')
            ->patchJson(route('admin.notifications.read', ['id' => $first->id]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $first->refresh();
        $this->assertNotNull($first->read_at);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    public function test_user_can_mark_single_notification_as_read_and_updates_unread_count(): void
    {
        $user = User::factory()->create();
        $first = $this->createNotificationFor($user);
        $this->createNotificationFor($user);

        $this->actingAs($user, 'web')
            ->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 2]);

        $this->actingAs($user, 'web')
            ->patchJson(route('notifications.read', ['id' => $first->id]))
            ->assertOk()
            ->assertJson(['success' => true]);

        $first->refresh();
        $this->assertNotNull($first->read_at);

        $this->actingAs($user, 'web')
            ->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    public function test_admin_can_delete_own_notification_and_updates_unread_count_cache(): void
    {
        $admin = Admin::factory()->create();
        $first = $this->createNotificationFor($admin);
        $second = $this->createNotificationFor($admin);

        // Prime cache first
        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 2]);

        $this->actingAs($admin, 'admin')
            ->deleteJson(route('admin.notifications.destroy', ['id' => $first->id]))
            ->assertOk()
            ->assertJson(['status' => 'deleted', 'success' => true]);

        $this->assertDatabaseMissing('notifications', ['id' => $first->id]);
        $this->assertDatabaseHas('notifications', ['id' => $second->id]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    public function test_user_can_delete_own_notification_and_updates_unread_count_cache(): void
    {
        $user = User::factory()->create();
        $first = $this->createNotificationFor($user);
        $second = $this->createNotificationFor($user);

        // Prime cache first
        $this->actingAs($user, 'web')
            ->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 2]);

        $this->actingAs($user, 'web')
            ->deleteJson(route('notifications.destroy', ['id' => $first->id]))
            ->assertOk()
            ->assertJson(['status' => 'deleted', 'success' => true]);

        $this->assertDatabaseMissing('notifications', ['id' => $first->id]);
        $this->assertDatabaseHas('notifications', ['id' => $second->id]);

        $this->actingAs($user, 'web')
            ->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertJson(['unread_count' => 1]);
    }

    public function test_idor_protection_prevents_deleting_another_users_or_admins_notification(): void
    {
        $adminA = Admin::factory()->create();
        $adminB = Admin::factory()->create();
        $adminBNotif = $this->createNotificationFor($adminB);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userBNotif = $this->createNotificationFor($userB);

        // Admin A cannot delete Admin B notification
        $this->actingAs($adminA, 'admin')
            ->deleteJson(route('admin.notifications.destroy', ['id' => $adminBNotif->id]))
            ->assertNotFound();
        $this->assertDatabaseHas('notifications', ['id' => $adminBNotif->id]);

        // User A cannot delete User B notification
        $this->actingAs($userA, 'web')
            ->deleteJson(route('notifications.destroy', ['id' => $userBNotif->id]))
            ->assertNotFound();
        $this->assertDatabaseHas('notifications', ['id' => $userBNotif->id]);
    }

    public function test_unauthenticated_guests_cannot_delete_notifications(): void
    {
        $admin = Admin::factory()->create();
        $user = User::factory()->create();
        $adminNotif = $this->createNotificationFor($admin);
        $userNotif = $this->createNotificationFor($user);

        $this->deleteJson(route('admin.notifications.destroy', ['id' => $adminNotif->id]))
            ->assertRedirect(route('login'));
        $this->deleteJson(route('notifications.destroy', ['id' => $userNotif->id]))
            ->assertRedirect(route('login'));
        $this->delete(route('notifications.destroy', ['id' => $userNotif->id]))
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('notifications', ['id' => $adminNotif->id]);
        $this->assertDatabaseHas('notifications', ['id' => $userNotif->id]);
    }

    public function test_snooze_routes_and_artisan_command_are_removed(): void
    {
        $admin = Admin::factory()->create();
        $user = User::factory()->create();
        $adminNotif = $this->createNotificationFor($admin);
        $userNotif = $this->createNotificationFor($user);

        $this->assertFalse(Route::has('admin.notifications.snooze'));
        $this->assertFalse(Route::has('notifications.snooze'));
        $this->assertArrayNotHasKey('reminder:snooze-check', Artisan::all());

        $this->actingAs($admin, 'admin')
            ->patchJson("/admin/notifications/{$adminNotif->id}/snooze", ['snooze_minutes' => 15])
            ->assertNotFound();

        $this->actingAs($user, 'web')
            ->patchJson("/notifications/{$userNotif->id}/snooze", ['snooze_minutes' => 15])
            ->assertNotFound();
    }
}
