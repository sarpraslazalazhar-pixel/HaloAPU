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
     * Kirim pesan pengingat otomatis dari Bot ke ruang chat Bot Pengingat milik Operator yang ditugaskan dan Admin Unit.
     *
     * @param Ticket $ticket Tiket terkait
     * @param string $reminderBody Isi teks pengingat
     * @param Admin|null $targetAdmin Admin tujuan spesifik (opsional)
     * @return array Array of created Messages
     */
    public static function sendTicketReminder(Ticket $ticket, string $reminderBody, ?Admin $targetAdmin = null): array
    {
        $createdMessages = [];

        try {
            // Kumpulkan admin/operator yang berhak menerima pengingat
            $admins = collect();

            if ($targetAdmin) {
                $admins->push($targetAdmin);
            } else {
                // 1. Operator / Admin yang ditugaskan menangani tiket
                if ($ticket->assignedAdmin) {
                    $admins->push($ticket->assignedAdmin);
                }

                // 2. Seluruh Admin dari Unit / SubUnit terkait
                $unit = $ticket->subUnit?->unit;
                if ($unit && $unit->admins->isNotEmpty()) {
                    $admins = $admins->concat($unit->admins);
                }

                // 3. Super Admin (mengawasi seluruh unit & tiket)
                try {
                    $superAdmins = Admin::role('Super Admin')->get();
                    $admins = $admins->concat($superAdmins);
                } catch (\Exception $se) {
                    // ignore
                }

                // 4. Fallback jika list admin masih kosong
                if ($admins->isEmpty()) {
                    $fallback = Admin::where('is_active', true)->first() ?: Admin::first();
                    if ($fallback) {
                        $admins->push($fallback);
                    }
                }
            }

            $admins = $admins->unique('id');

            foreach ($admins as $admin) {
                // Cari atau buat ruang percakapan khusus Bot Pengingat untuk admin ini
                $conversation = Conversation::firstOrCreate([
                    'type' => 'admin_bot_reminder',
                    'admin_one_id' => $admin->id,
                ], [
                    'last_message_at' => now(),
                ]);

                // Buat pesan pengingat sistem dengan lampiran kartu tiket
                $message = Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => Admin::class,
                    'sender_id' => 0,
                    'ticket_id' => $ticket->id,
                    'body' => $reminderBody,
                ]);

                $conversation->update(['last_message_at' => now()]);

                // Broadcast ke channel admin dan channel percakapan bot
                broadcast(new ChatMessageSent($message));

                $createdMessages[] = $message;
            }
        } catch (\Exception $e) {
            Log::error("ChatReminderService Error: " . $e->getMessage(), [
                'ticket_id' => $ticket->id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $createdMessages;
    }
}
