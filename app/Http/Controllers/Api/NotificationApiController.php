<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    /**
     * Get user's notifications.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->paginate($request->get('per_page', 15));

        $formattedNotifications = $notifications->getCollection()->map(function ($notif) {
            $data = $notif->data;

            // Determine notification type from the data or class
            $type = $data['type'] ?? 'ticket';

            return [
                'id' => $notif->id,
                'title' => $data['title'] ?? 'Notifikasi',
                'body' => $data['body'] ?? $data['message'] ?? '',
                'type' => $type,
                'createdAt' => $notif->created_at->toIso8601String(),
                'isRead' => $notif->read_at !== null,
                'ticketId' => $data['ticket_id'] ?? $data['ticketId'] ?? null,
            ];
        });

        return response()->json([
            'data' => $formattedNotifications,
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Get count of unread notifications.
     */
    public function unreadCount(Request $request)
    {
        $count = $request->user()->unreadNotifications()->count();

        return response()->json([
            'data' => ['count' => $count],
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan'], 404);
        }

        $notification->markAsRead();

        return response()->json([
            'message' => 'Notifikasi ditandai sudah dibaca',
        ]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json([
            'message' => 'Semua notifikasi ditandai sudah dibaca',
        ]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (!$notification) {
            return response()->json(['message' => 'Notifikasi tidak ditemukan'], 404);
        }

        $notification->delete();

        return response()->json([
            'message' => 'Notifikasi berhasil dihapus',
        ]);
    }
}
