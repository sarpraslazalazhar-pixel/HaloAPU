<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Models\Admin;
use App\Models\ChatAttachment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class TicketChatBridgeService
{
    /**
     * Sinkronkan balasan dari tiket (Mobile / Web Ticket Detail) ke ruang percakapan Chat Web.
     *
     * @param Ticket $ticket
     * @param TicketLog $log
     * @return Message|null
     */
    public static function syncTicketReplyToChat(Ticket $ticket, TicketLog $log): ?Message
    {
        try {
            $userId = (int) $ticket->user_id;
            if (!$userId) {
                return null;
            }

            $targetAdminIds = collect();

            if ($log->admin_id) {
                $senderType = Admin::class;
                $senderId = (int) $log->admin_id;
                $targetAdminIds->push($senderId);
            } else {
                $senderType = User::class;
                $senderId = $userId;

                // Admin tujuan: prioritas operator tiket -> admin unit terkait -> admin pertama
                if ($ticket->assigned_admin_id) {
                    $targetAdminIds->push((int) $ticket->assigned_admin_id);
                }

                $unitAdmins = $ticket->subUnit?->unit?->admins;
                if ($unitAdmins && $unitAdmins->isNotEmpty()) {
                    $targetAdminIds = $targetAdminIds->concat($unitAdmins->pluck('id'));
                }

                if ($targetAdminIds->isEmpty()) {
                    $firstAdmin = Admin::value('id');
                    if ($firstAdmin) {
                        $targetAdminIds->push((int) $firstAdmin);
                    }
                }
            }

            $targetAdminIds = $targetAdminIds->unique()->filter();
            if ($targetAdminIds->isEmpty()) {
                return null;
            }

            $body = trim((string) $log->catatan);
            if (empty($body) && $log->attachments && $log->attachments->isNotEmpty()) {
                $body = '[Lampiran Tiket]';
            }

            $firstMessage = null;

            foreach ($targetAdminIds as $adminId) {
                // Cari atau buat percakapan direct antara User dan masing-masing Admin terkait
                $conversation = Conversation::firstOrCreate([
                    'type' => 'user_admin_direct',
                    'user_id' => $userId,
                    'admin_one_id' => $adminId,
                ], [
                    'last_message_at' => now(),
                ]);

                // Cegah duplikasi jika pesan persis sama baru saja dibuat < 10 detik
                $alreadyBridged = Message::where('conversation_id', $conversation->id)
                    ->where('ticket_id', $ticket->id)
                    ->where('body', $body)
                    ->where('created_at', '>=', now()->subSeconds(10))
                    ->exists();

                if ($alreadyBridged) {
                    continue;
                }

                $chatMessage = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => $senderType,
                    'sender_id' => $senderId,
                    'ticket_id' => $ticket->id,
                    'body' => $body,
                ]);

                if (!$firstMessage) {
                    $firstMessage = $chatMessage;
                }

                // Sinkronkan lampiran jika ada
                if ($log->attachments && $log->attachments->isNotEmpty()) {
                    foreach ($log->attachments as $att) {
                        ChatAttachment::create([
                            'message_id' => $chatMessage->id,
                            'file_name' => $att->original_name ?: basename($att->file_path),
                            'file_path' => $att->file_path,
                            'file_type' => $att->mime_type ?: 'application/octet-stream',
                            'file_size' => (int) ($att->file_size ?: 0),
                        ]);
                    }
                }

                $conversation->update(['last_message_at' => now()]);

                // Broadcast real-time ke web chat
                try {
                    broadcast(new ChatMessageSent($chatMessage))->toOthers();
                } catch (\Throwable $be) {
                    // Abaikan jika broadcast socket offline
                }
            }

            return $firstMessage;
        } catch (\Throwable $e) {
            Log::error("TicketChatBridgeService Error (syncTicketReplyToChat): " . $e->getMessage(), [
                'ticket_id' => $ticket->id ?? null,
                'log_id' => $log->id ?? null,
            ]);
            return null;
        }
    }

    /**
     * Sinkronkan pesan dari ruang Chat Web ke riwayat tiket (agar muncul di mobile & web timeline).
     *
     * @param Message $message
     * @return TicketLog|null
     */
    public static function syncChatMessageToTicket(Message $message): ?TicketLog
    {
        try {
            $ticketId = $message->ticket_id;

            // Jika tidak langsung ada ticket_id, cari dari reply pesan atau percakapan user
            if (!$ticketId && $message->reply_to_message_id) {
                $parent = Message::find($message->reply_to_message_id);
                if ($parent && $parent->ticket_id) {
                    $ticketId = $parent->ticket_id;
                    $message->update(['ticket_id' => $ticketId]);
                }
            }

            if (!$ticketId) {
                $conversation = $message->conversation;
                if ($conversation && $conversation->user_id) {
                    // Cari tiket yang pernah disebut di percakapan ini
                    $ticketId = Message::where('conversation_id', $conversation->id)
                        ->whereNotNull('ticket_id')
                        ->latest('id')
                        ->value('ticket_id');

                    // Jika belum pernah disebut, cari tiket aktif milik user
                    if (!$ticketId) {
                        $ticketId = Ticket::where('user_id', $conversation->user_id)
                            ->whereIn('status', ['open', 'on_proses', 'pending', 'need_revision'])
                            ->latest('updated_at')
                            ->value('id');
                    }

                    // Fallback ke tiket terbaru apapun milik user ini
                    if (!$ticketId) {
                        $ticketId = Ticket::where('user_id', $conversation->user_id)
                            ->latest('id')
                            ->value('id');
                    }

                    if ($ticketId) {
                        $message->update(['ticket_id' => $ticketId]);
                    }
                }
            }

            if (!$ticketId) {
                return null;
            }

            $ticket = Ticket::find($ticketId);
            if (!$ticket) {
                return null;
            }

            $body = trim((string) $message->body);
            if (empty($body) && $message->attachments()->exists()) {
                $body = '[Lampiran Chat]';
            }

            // Cegah duplikasi jika log persis sama baru saja dibuat < 10 detik
            $alreadyBridged = TicketLog::where('ticket_id', $ticket->id)
                ->where('catatan', $body)
                ->where('timestamp', '>=', now()->subSeconds(10))
                ->exists();

            if ($alreadyBridged) {
                return null;
            }

            $isAdmin = $message->sender_type === Admin::class;

            $log = TicketLog::create([
                'ticket_id' => $ticket->id,
                'admin_id' => $isAdmin ? $message->sender_id : null,
                'aksi' => 'balasan',
                'catatan' => $body,
                'timestamp' => now(),
            ]);

            // Salin lampiran chat jika ada
            $attachments = $message->attachments;
            if ($attachments && $attachments->isNotEmpty()) {
                foreach ($attachments as $att) {
                    TicketAttachment::create([
                        'ticket_id' => $ticket->id,
                        'field_id' => null,
                        'ticket_log_id' => $log->id,
                        'file_path' => $att->file_path,
                        'original_name' => $att->file_name,
                        'mime_type' => $att->file_type,
                        'file_size' => (int) ($att->file_size ?: 0),
                        'wajib' => false,
                    ]);
                }
            }

            return $log;
        } catch (\Throwable $e) {
            Log::error("TicketChatBridgeService Error (syncChatMessageToTicket): " . $e->getMessage(), [
                'message_id' => $message->id ?? null,
            ]);
            return null;
        }
    }
}
