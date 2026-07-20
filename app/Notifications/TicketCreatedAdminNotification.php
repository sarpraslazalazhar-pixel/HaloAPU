<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketCreatedAdminNotification extends Notification
{
    use Queueable;

    public $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable instanceof \App\Models\Admin) {
            $channels[] = 'database';
            // Jika admin punya nomor WA, kirim juga via WhatsApp
            if (!empty($notifiable->no_wa)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        if ($notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable) {
            // Fallback: kirim ke nomor_wa_utama jika ada
            $nomorUtama = \App\Models\SystemConfig::getValue('nomor_wa_utama');
            if (!empty($nomorUtama)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $pembuat = $this->ticket->user->name ?: $this->ticket->user->username;

        return [
            'type' => 'ticket_created',
            'ticket_id' => $this->ticket->id,
            'title' => 'Tiket Baru Masuk',
            'message' => "Ada tiket baru dari {$pembuat} terkait layanan {$layanan}.",
            'url' => url('/admin/tiket/' . $this->ticket->id),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $pembuat = $this->ticket->user->name ?: $this->ticket->user->username;
        $url = url('/admin/tiket/' . $this->ticket->id);
        
        $message = "Halo Admin,\n\n";
        $message .= "Ada *Tiket Baru Masuk* dari *{$pembuat}* terkait layanan *{$layanan}*.\n\n";
        $message .= "Silakan segera direspon melalui link berikut:\n{$url}\n\n";
        $message .= "Sistem Halo APU";

        // Tentukan nomor penerima
        if ($notifiable instanceof \App\Models\Admin) {
            $receiver = $notifiable->no_wa;
        } else {
            // AnonymousNotifiable → ambil nomor_wa_utama
            $receiver = \App\Models\SystemConfig::getValue('nomor_wa_utama');
        }

        return [
            'receiver' => $receiver,
            'message' => $message,
        ];
    }
}
