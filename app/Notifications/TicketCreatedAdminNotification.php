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
        }
        if ($notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable) {
            $channels[] = \App\Channels\WhatsAppChannel::class;
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

        $nomorAdmin = class_exists(\App\Models\SystemConfig::class) ? \App\Models\SystemConfig::getValue('nomor_wa_utama') : null;

        return [
            'receiver' => $nomorAdmin,
            'message' => $message,
        ];
    }
}
