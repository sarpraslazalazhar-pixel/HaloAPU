<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class SlaEscalationNotification extends Notification
{
    use Queueable, SerializesModels;

    public function __construct(
        protected Ticket $ticket,
        protected string $breachType,
        protected string $priority
    ) {}

    public function via(object $notifiable): array
    {
        $priority = strtolower($this->priority);
        if ($priority === 'tinggi' || $priority === 'urgen') {
            return ['database', WhatsAppChannel::class];
        }

        return ['database', 'mail'];
    }

    public function toDatabase(object $notifiable): array
    {
        $jenisBreachLabel = $this->breachType === 'respon' ? 'Respon' : 'Penyelesaian';

        return [
            'ticket_id' => $this->ticket->id,
            'judul_tiket' => $this->ticket->judul,
            'unit' => $this->ticket->subUnit?->unit?->nama_unit,
            'sub_unit' => $this->ticket->subUnit?->nama_layanan,
            'prioritas' => $this->priority,
            'jenis_breach' => $jenisBreachLabel,
            'judul' => "⚠️ Breach SLA {$jenisBreachLabel}",
            'pesan' => "Tiket #{$this->ticket->id} \"{$this->ticket->judul}\" telah melewati batas SLA ({$jenisBreachLabel}). Segera tangani!",
            'icon' => 'alert-triangle',
            'aksi_url' => "/admin/tiketing/{$this->ticket->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jenisBreachLabel = $this->breachType === 'respon' ? 'Respon' : 'Penyelesaian';

        return (new MailMessage)
            ->subject("⚠️ Breach SLA {$jenisBreachLabel} — Tiket #{$this->ticket->id}")
            ->greeting("Perhatian, {$notifiable->name}!")
            ->line("Tiket berikut telah melewati batas SLA **{$jenisBreachLabel}**:")
            ->line("**Tiket:** #{$this->ticket->id} — {$this->ticket->judul}")
            ->line("**Unit:** {$this->ticket->subUnit?->unit?->nama_unit}")
            ->line("**Sub Unit:** {$this->ticket->subUnit?->nama_layanan}")
            ->line("**Prioritas:** {$this->priority}")
            ->line("**Jenis:** SLA {$jenisBreachLabel}")
            ->line("**Status:** {$this->ticket->status}")
            ->action('Lihat Tiket', url("/admin/tiketing/{$this->ticket->id}"))
            ->line('**⛔ BREACH! Tiket ini sudah melewati batas SLA. Tindakan segera diperlukan.**');
    }

    public function toWhatsApp(object $notifiable): array
    {
        $jenisBreachLabel = $this->breachType === 'respon' ? 'Respon' : 'Penyelesaian';
        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Admin');
        $url = url('/admin/tiket/' . $this->ticket->id);

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "Ada info baru nih. Pengajuan *{$this->ticket->formatted_id}* udah melewati batas SLA {$jenisBreachLabel} ya 😊\n\n";
        $message .= "Biar lebih jelas, langsung aja cek detailnya di sini:\n{$url}\n\n";
        $message .= "Terima kasih";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }
}
