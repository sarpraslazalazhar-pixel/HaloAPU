<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;

class NotificationController extends Controller
{
    private function getNotifiableUser(Request $request)
    {
        return $request->user('admin') ?? $request->user('web') ?? $request->user();
    }

    /**
     * Ambil jumlah notifikasi yang belum dibaca.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $this->getNotifiableUser($request);
        $count = $user ? $user->unreadNotifications()->count() : 0;

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * Halaman semua notifikasi (paginated, dengan filter).
     */
    public function index(Request $request)
    {
        $user = $this->getNotifiableUser($request);
        if (!$user) {
            if ($request->wantsJson()) {
                return response()->json(['notifications' => ['data' => []]]);
            }
            return redirect()->back();
        }

        $query = $user->notifications();

        // Filter: status
        $status = $request->get('status');
        if ($status === 'unread') {
            $query->whereNull('read_at');
        } elseif ($status === 'read') {
            $query->whereNotNull('read_at');
        }

        // Filter: tipe
        $type = $request->get('type');
        if ($type) {
            $query->where('type', 'LIKE', "%{$type}%");
        }

        $notifications = $query->latest()->paginate(20);

        // Jika dipanggil via AJAX/axios, kembalikan JSON
        if ($request->wantsJson()) {
            return response()->json([
                'notifications' => $notifications
            ]);
        }

        $isAdmin = (bool)$request->user('admin');
        $view = $isAdmin ? 'Admin/Notifications/Index' : 'User/Notifications/Index';

        return Inertia::render($view, [
            'notifications' => $notifications,
            'filters' => [
                'status' => $status,
                'type' => $type,
            ],
        ]);
    }

    /**
     * Tandai satu notifikasi sebagai sudah dibaca.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $this->getNotifiableUser($request);
        if ($user) {
            $notification = $user->notifications()->findOrFail($id);
            $notification->markAsRead();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Snooze notifikasi.
     */
    public function snooze(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'snooze_minutes' => 'required|integer|in:15,30,60,120,1440',
        ]);

        $user = $this->getNotifiableUser($request);
        if ($user) {
            $notification = $user->notifications()->findOrFail($id);

            $data = $notification->data;
            $data['snoozed_until'] = now()->addMinutes($validated['snooze_minutes'])->toISOString();
            $data['snoozed'] = true;

            $notification->update([
                'data' => $data,
                'read_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'snoozed_until' => $data['snoozed_until'],
            ]);
        }

        return response()->json(['success' => false], 401);
    }

    /**
     * Tandai notifikasi sebagai selesai (done).
     */
    public function markAsDone(Request $request, string $id): JsonResponse
    {
        $user = $this->getNotifiableUser($request);
        if ($user) {
            $notification = $user->notifications()->findOrFail($id);

            $data = $notification->data;
            $data['done_at'] = now()->toISOString();

            $notification->update([
                'data' => $data,
                'read_at' => $notification->read_at ?? now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Tandai semua notifikasi sebagai sudah dibaca.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $this->getNotifiableUser($request);
        if ($user) {
            $user->unreadNotifications->markAsRead();
        }

        return response()->json(['success' => true]);
    }
}
