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

class OpenTicketReminderNotification extends Notification
{
    use Queueable, SerializesModels;

    protected int $hariOpen;

    public function __construct(
        protected Ticket $ticket
    ) {
        $this->hariOpen = (int) max(1, now()->diffInDays($this->ticket->created_at));
    }

    public function via(object $notifiable): array
    {
        $config = ReminderConfig::where('jenis_reminder', 'open_lama')->first();
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
            'status' => 'Open',
            'hari_open' => $this->hariOpen,
            'unit' => $this->ticket->subUnit?->unit?->nama_unit,
            'sub_unit' => $this->ticket->subUnit?->nama_layanan,
            'judul' => "Pengingat Tiket Baru/Open",
            'pesan' => "Tiket #{$this->ticket->formatted_id} \"{$this->ticket->judul}\" belum ditindaklanjuti selama {$this->hariOpen} hari. Silakan tugaskan operator atau proses tiket.",
            'icon' => 'alert-circle',
            'aksi_url' => "/admin/tiket/{$this->ticket->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Pengingat Tiket Open/Baru — #{$this->ticket->formatted_id}")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Tiket berikut masih berstatus **Open/Baru** dan belum ditindaklanjuti selama **{$this->hariOpen} hari**:")
            ->line("**Tiket:** #{$this->ticket->formatted_id} — {$this->ticket->judul}")
            ->line("**Unit:** {$this->ticket->subUnit?->unit?->nama_unit}")
            ->line("**Dibuat Pada:** {$this->ticket->created_at->format('d M Y H:i')}")
            ->action('Lihat & Proses Tiket', url("/admin/tiket/{$this->ticket->id}"))
            ->line('Mohon segera menugaskan operator atau memproses pengajuan ini.');
    }

    public function toWhatsApp(object $notifiable): array
    {
        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Admin');
        $url = url('/admin/tiket/' . $this->ticket->id);

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "Pengingat tiket baru: Pengajuan *#{$this->ticket->formatted_id}* (\"{$this->ticket->judul}\") masih berstatus Open dan belum diproses selama {$this->hariOpen} hari.\n\n";
        $message .= "Silakan cek dan tugaskan operator melalui link ini:\n{$url}\n\n";
        $message .= "Terima kasih";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
