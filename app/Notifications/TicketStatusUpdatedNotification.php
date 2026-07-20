<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TicketStatusUpdatedNotification extends Notification
{
    use Queueable;

    public $ticket;
    public $catatan;

    public function __construct(Ticket $ticket, $catatan)
    {
        $this->ticket = $ticket;
        $this->catatan = $catatan;
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
        $statusStr = ucwords(str_replace('_', ' ', $this->ticket->status));
        return [
            'type' => 'ticket_status_updated',
            'ticket_id' => $this->ticket->id,
            'title' => 'Status Tiket Diubah',
            'message' => 'Status tiket Anda berubah menjadi ' . $statusStr . '. Catatan: ' . $this->catatan,
            'url' => route('tiket.show', $this->ticket->id),
        ];
    }

    public function toWhatsApp(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $nama = $notifiable->name ?: $notifiable->username;
        $statusStr = ucwords(str_replace('_', ' ', $this->ticket->status));
        $url = route('tiket.show', $this->ticket->id);
        
        $message = "🔄 *STATUS TIKET DIPERBARUI*\n\n";
        $message .= "Halo *{$nama}*\n\n";
        $message .= "Status pengajuan Kamu terkait layanan *{$layanan}* telah diperbarui.\n\n";
        $message .= "Status Saat Ini: *{$statusStr}*\n";
        if (!empty($this->catatan)) {
            $message .= "Catatan: _{$this->catatan}_\n\n";
        } else {
            $message .= "\n";
        }
        
        if ($this->ticket->status === 'solve' || $this->ticket->status === 'selesai') {
            $message .= "Karena tiket Kamu telah selesai, mohon ketersediaannya untuk memberikan *Rating Kepuasan (CSAT)* melalui link berikut ini:\n{$url}\n\n";
        } else {
            $message .= "Kamu bisa melihat detail tiket melalui link berikut:\n{$url}\n\n";
        }
        
        $message .= "Terima kasih,\nTim Halo APU";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
