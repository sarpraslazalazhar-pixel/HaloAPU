<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\ReminderConfig;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class PendingTicketReminderNotification extends Notification
{
    use Queueable, SerializesModels;

    protected int $hariPending;

    public function __construct(
        protected Ticket $ticket
    ) {
        $this->hariPending = (int) now()->diffInDays($this->ticket->updated_at);
    }

    public function via(object $notifiable): array
    {
        $config = ReminderConfig::where('jenis_reminder', 'pending_lama')->first();
        $channels = ['database'];

        if ($config && is_array($config->channel_aktif)) {
            if (in_array('email', $config->channel_aktif)) {
                $channels[] = 'mail';
            }
            if (in_array('whatsapp', $config->channel_aktif)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'ticket_id' => $this->ticket->id,
            'judul_tiket' => $this->ticket->judul,
            'status' => 'Pending',
            'hari_pending' => $this->hariPending,
            'unit' => $this->ticket->subUnit?->unit?->nama_unit,
            'sub_unit' => $this->ticket->subUnit?->nama_layanan,
            'judul' => "Tiket Pending Lama",
            'pesan' => "Tiket #{$this->ticket->id} \"{$this->ticket->judul}\" sudah pending selama {$this->hariPending} hari. Silakan tindak lanjuti.",
            'icon' => 'clock',
            'aksi_url' => "/admin/tiketing/{$this->ticket->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Tiket Pending Lama — #{$this->ticket->id}")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Tiket berikut sudah dalam status Pending selama **{$this->hariPending} hari**:")
            ->line("**Tiket:** #{$this->ticket->id} — {$this->ticket->judul}")
            ->line("**Unit:** {$this->ticket->subUnit?->unit?->nama_unit}")
            ->line("**Pending Sejak:** {$this->ticket->updated_at->format('d M Y H:i')}")
            ->action('Lihat Tiket', url("/admin/tiketing/{$this->ticket->id}"))
            ->line('Mohon segera ditindaklanjuti.');
    }

    public function toWhatsApp(object $notifiable): array
    {
        return [
            'receiver' => $notifiable->no_wa,
            'message' => "⏳ *Tiket Pending Lama*\n\n"
                . "Tiket: #{$this->ticket->id}\n"
                . "Judul: {$this->ticket->judul}\n"
                . "Pending: {$this->hariPending} hari\n"
                . "Unit: {$this->ticket->subUnit?->unit?->nama_unit}\n\n"
                . "Mohon segera ditindaklanjuti.",
        ];
    }
}
