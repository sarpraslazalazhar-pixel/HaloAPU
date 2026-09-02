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
            $data = $notif->data ?? [];
            $rawType = strtolower($data['type'] ?? 'ticket');

            // Map backend notification type to Flutter supported enum:
            // [ticket, reply, solved, revision, rejected, csat, sla]
            $type = match ($rawType) {
                'ticket_created', 'ticket_assigned', 'ticket' => 'ticket',
                'ticket_comment', 'reply', 'comment' => 'reply',
                'solve', 'solved', 'ticket_solved' => 'solved',
                'revision', 'need_revision', 'revision_requested' => 'revision',
                'reject', 'rejected', 'ticket_rejected' => 'rejected',
                'csat', 'csat_reminder' => 'csat',
                'sla', 'sla_escalation' => 'sla',
                'ticket_status_updated', 'ticket_status_updated_operator' => (function () use ($data) {
                    $title = strtolower($data['title'] ?? '');
                    $body = strtolower($data['message'] ?? $data['body'] ?? '');
                    if (str_contains($body, 'solve') || str_contains($body, 'selesai') || str_contains($title, 'selesai')) {
                        return 'solved';
                    }
                    if (str_contains($body, 'reject') || str_contains($body, 'tolak') || str_contains($title, 'tolak')) {
                        return 'rejected';
                    }
                    if (str_contains($body, 'revisi')) {
                        return 'revision';
                    }
                    return 'ticket';
                })(),
                default => 'ticket',
            };

            return [
                'id' => (string) $notif->id,
                'title' => $data['title'] ?? 'Pembaruan Tiket',
                'body' => $data['body'] ?? $data['message'] ?? '',
                'type' => $type,
                'createdAt' => $notif->created_at ? $notif->created_at->toIso8601String() : now()->toIso8601String(),
                'isRead' => $notif->read_at !== null,
                'ticketId' => isset($data['ticket_id']) ? (string) $data['ticket_id'] : (isset($data['ticketId']) ? (string) $data['ticketId'] : null),
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
