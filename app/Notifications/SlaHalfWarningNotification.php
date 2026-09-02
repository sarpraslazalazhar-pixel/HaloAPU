<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Channels\FcmChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class SlaHalfWarningNotification extends Notification
{
    use Queueable, SerializesModels;

    public function __construct(
        protected Ticket $ticket,
        protected string $warningType, // 'respon' atau 'penyelesaian'
        protected string $priority
    ) {}

    public function via(object $notifiable): array
    {
        $config = \App\Models\ReminderConfig::where('jenis_reminder', 'sla_half')->first();
        if ($config && !$config->aktif) {
            return [];
        }

        $channels = [];
        $activeChannels = $config ? ($config->channel_aktif ?? ['in_app', 'email']) : ['in_app', 'email'];

        if (in_array('in_app', $activeChannels)) {
            $channels[] = 'database';
        }

        if (in_array('email', $activeChannels)) {
            $channels[] = 'mail';
        }

        if (in_array('whatsapp', $activeChannels) && !empty($notifiable->no_wa)) {
            $channels[] = WhatsAppChannel::class;
        }

        if (!empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    public function toFcm(object $notifiable): array
    {
        $jenisLabel = $this->warningType === 'respon' ? 'Respon' : 'Penyelesaian';
        return [
            'title' => "⏳ Peringatan 50% SLA {$jenisLabel}: Tiket #{$this->ticket->formatted_id}",
            'body' => "Tiket \"{$this->ticket->judul}\" telah berjalan 50% dari batas waktu SLA {$jenisLabel}. Segera tindak lanjuti!",
            'ticket_id' => (string) $this->ticket->id,
            'url' => url('/admin/tiket/' . $this->ticket->id),
            'type' => 'sla_half_warning',
        ];
    }

    public function toDatabase(object $notifiable): array
    {
        $jenisLabel = $this->warningType === 'respon' ? 'Respon' : 'Penyelesaian';

        return [
            'ticket_id' => $this->ticket->id,
            'judul_tiket' => $this->ticket->judul,
            'unit' => $this->ticket->subUnit?->unit?->nama_unit,
            'sub_unit' => $this->ticket->subUnit?->nama_layanan,
            'prioritas' => $this->priority,
            'jenis_warning' => $jenisLabel,
            'judul' => "⏳ Peringatan 50% SLA {$jenisLabel}",
            'pesan' => "Tiket #{$this->ticket->formatted_id} \"{$this->ticket->judul}\" telah mencapai 50% batas waktu SLA {$jenisLabel}. Mohon segera ditangani sebelum terlambat.",
            'icon' => 'clock',
            'aksi_url' => "/admin/tiket/{$this->ticket->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jenisLabel = $this->warningType === 'respon' ? 'Respon' : 'Penyelesaian';

        return (new MailMessage)
            ->subject("⏳ Peringatan 50% SLA {$jenisLabel} — Tiket #{$this->ticket->formatted_id}")
            ->greeting("Halo, {$notifiable->name}!")
            ->line("Tiket berikut telah mencapai **50% batas waktu SLA {$jenisLabel}**:")
            ->line("**Tiket:** #{$this->ticket->formatted_id} — {$this->ticket->judul}")
            ->line("**Unit:** {$this->ticket->subUnit?->unit?->nama_unit}")
            ->line("**Sub Unit:** {$this->ticket->subUnit?->nama_layanan}")
            ->line("**Prioritas:** {$this->priority}")
            ->line("**Status:** {$this->ticket->status}")
            ->action('Lihat Tiket', url("/admin/tiket/{$this->ticket->id}"))
            ->line('**Mohon segera diproses agar tidak melewati batas SLA.**');
    }

    public function toWhatsApp(object $notifiable): array
    {
        $jenisLabel = $this->warningType === 'respon' ? 'Respon' : 'Penyelesaian';
        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Admin');
        $url = url('/admin/tiket/' . $this->ticket->id);

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "Peringatan SLA: Pengajuan *#{$this->ticket->formatted_id}* (\"{$this->ticket->judul}\") telah berjalan 50% dari batas SLA {$jenisLabel}.\n\n";
        $message .= "Cek dan proses tiket di sini:\n{$url}\n\n";
        $message .= "Terima kasih";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
