<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketCreatedUserNotification extends Notification
{
    use Queueable;

    public $ticket;

    public function __construct(Ticket $ticket)
    {
        $this->ticket = $ticket;
    }

    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (!empty($notifiable->no_wa)) {
            $channels[] = WhatsAppChannel::class;
        }
        return $channels;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_created',
            'ticket_id' => $this->ticket->id,
            'title' => 'Tiket Baru Berhasil Dibuat',
            'message' => 'Tiket Anda dengan layanan ' . ($this->ticket->subUnit->nama_layanan ?? '-') . ' telah kami terima dan akan segera diproses.',
            'url' => route('tiket.show', $this->ticket->id),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $nama = $notifiable->name ?: $notifiable->username;
        $url = route('tiket.show', $this->ticket->id);
        
        $message = "✅ *TIKET BERHASIL DIAJUKAN*\n\n";
        $message .= "Halo *{$nama}*\n\n";
        $message .= "Pengajuan Kamu dengan layanan *{$layanan}* telah terdaftar di sistem ticketing Kami.\n\n";
        $message .= "Kamu bisa memantau status pengajuan melalui link berikut:\n{$url}\n\n";
        $message .= "Terima kasih,\nTim Halo APU";

        return [
            'to' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
