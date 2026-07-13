<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;

class NotificationController extends Controller
{
    /**
     * Ambil jumlah notifikasi yang belum dibaca.
     * Dipanggil via usePoll() setiap 15 detik.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = $request->user('admin')
            ->unreadNotifications()
            ->count();

        return response()->json([
            'unread_count' => $count,
        ]);
    }

    /**
     * Halaman semua notifikasi (paginated, dengan filter).
     */
    public function index(Request $request)
    {
        $query = $request->user('admin')->notifications();

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

        return Inertia::render('Admin/Notifications/Index', [
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
        $notification = $request->user('admin')
            ->notifications()
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Snooze notifikasi — set snoozed_until di kolom data.
     * Scheduler akan re-notify saat expired.
     */
    public function snooze(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'snooze_minutes' => 'required|integer|in:15,30,60,120,1440',
        ]);

        $notification = $request->user('admin')
            ->notifications()
            ->findOrFail($id);

        $data = $notification->data;
        $data['snoozed_until'] = now()->addMinutes($validated['snooze_minutes'])->toISOString();
        $data['snoozed'] = true;

        $notification->update([
            'data' => $data,
            'read_at' => now(), // Mark as read saat di-snooze
        ]);

        return response()->json([
            'success' => true,
            'snoozed_until' => $data['snoozed_until'],
        ]);
    }

    /**
     * Tandai notifikasi sebagai selesai (done).
     * Menambahkan flag done_at di data JSON.
     */
    public function markAsDone(Request $request, string $id): JsonResponse
    {
        $notification = $request->user('admin')
            ->notifications()
            ->findOrFail($id);

        $data = $notification->data;
        $data['done_at'] = now()->toISOString();

        $notification->update([
            'data' => $data,
            'read_at' => $notification->read_at ?? now(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Tandai semua notifikasi sebagai sudah dibaca.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user('admin')->unreadNotifications->markAsRead();

        return response()->json(['success' => true]);
    }
}
