<?php

namespace App\Services;

use App\Events\ChatMessageSent;
use App\Models\Admin;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ChatReminderService
{
    /**
     * Kirim pesan pengingat otomatis ke ruang chat privat antara Pemohon dan Admin/Operator.
     *
     * @param Ticket $ticket Tiket terkait
     * @param string $reminderBody Isi teks pengingat
     * @param Admin|null $targetAdmin Admin tujuan (opsional)
     * @return Message|null
     */
    public static function sendTicketReminder(Ticket $ticket, string $reminderBody, ?Admin $targetAdmin = null): ?Message
    {
        try {
            $user = $ticket->user;
            if (!$user) {
                return null;
            }

            // Tentukan admin penerima / pengirim pesan
            $admin = $targetAdmin ?: $ticket->assignedAdmin;
            if (!$admin) {
                $unit = $ticket->subUnit?->unit;
                if ($unit && $unit->admins->isNotEmpty()) {
                    $admin = $unit->admins->first();
                } else {
                    $admin = Admin::first();
                }
            }

            if (!$admin) {
                return null;
            }

            // Cari atau buat percakapan langsung antara user dan admin
            $conversation = Conversation::firstOrCreate([
                'type' => 'user_admin_direct',
                'user_id' => $user->id,
                'admin_one_id' => $admin->id,
            ], [
                'last_message_at' => now(),
            ]);

            // Buat pesan pengingat sistem dengan lampiran kartu tiket
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => Admin::class,
                'sender_id' => $admin->id,
                'ticket_id' => $ticket->id,
                'body' => $reminderBody,
            ]);

            $conversation->update(['last_message_at' => now()]);

            // Broadcast ke semua listener realtime (ChatWindow, Sidebar, User & Admin channels)
            broadcast(new ChatMessageSent($message));

            return $message;
        } catch (\Exception $e) {
            Log::error("ChatReminderService Error: " . $e->getMessage(), [
                'ticket_id' => $ticket->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
