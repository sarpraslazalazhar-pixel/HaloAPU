<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Models\Ticket;
use App\Models\TicketSlaTracking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaEscalationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Ticket $ticket,
        protected TicketSlaTracking $sla,
        protected int $tier,
        protected array $viaChannels = ['database', 'mail']
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->viaChannels;

        // Tier 3: selalu tambahkan WhatsApp
        if ($this->tier >= 3 && !in_array(WhatsAppChannel::class, $channels)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        $jenisBreachLabel = !$this->sla->responded_at ? 'Respon' : 'Penyelesaian';

        return [
            'ticket_id' => $this->ticket->id,
            'judul_tiket' => $this->ticket->judul,
            'unit' => $this->ticket->subUnit?->unit?->nama,
            'sub_unit' => $this->ticket->subUnit?->nama,
            'tier' => $this->tier,
            'jenis_breach' => $jenisBreachLabel,
            'judul' => "⚠️ Eskalasi SLA Tier {$this->tier}",
            'pesan' => "Tiket #{$this->ticket->id} \"{$this->ticket->judul}\" telah mencapai SLA Tier {$this->tier} ({$jenisBreachLabel}). Segera tangani!",
            'icon' => 'alert-triangle',
            'aksi_url' => "/admin/tiketing/{$this->ticket->id}",
            'prioritas' => $this->tier >= 3 ? 'tinggi' : 'normal',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $jenisBreachLabel = !$this->sla->responded_at ? 'Respon' : 'Penyelesaian';

        $mail = (new MailMessage)
            ->subject("⚠️ Eskalasi SLA Tier {$this->tier} — Tiket #{$this->ticket->id}")
            ->greeting("Perhatian, {$notifiable->name}!")
            ->line("Tiket berikut telah mencapai SLA **Tier {$this->tier}**:")
            ->line("**Tiket:** #{$this->ticket->id} — {$this->ticket->judul}")
            ->line("**Unit:** {$this->ticket->subUnit?->unit?->nama}")
            ->line("**Sub Unit:** {$this->ticket->subUnit?->nama}")
            ->line("**Jenis:** SLA {$jenisBreachLabel}")
            ->line("**Status:** {$this->ticket->status}")
            ->action('Lihat Tiket', url("/admin/tiketing/{$this->ticket->id}"));

        if ($this->tier >= 3) {
            $mail->line('**⛔ BREACH! Tiket ini sudah melewati batas SLA. Tindakan segera diperlukan.**');
        }

        return $mail;
    }

    public function toWhatsApp(object $notifiable): array
    {
        $jenisBreachLabel = !$this->sla->responded_at ? 'Respon' : 'Penyelesaian';
        $emoji = $this->tier >= 3 ? '🔴' : ($this->tier === 2 ? '🟠' : '🟡');

        return [
            'receiver' => $notifiable->no_wa,
            'message' => "{$emoji} *Eskalasi SLA Tier {$this->tier}*\n\n"
                . "Tiket: #{$this->ticket->id}\n"
                . "Judul: {$this->ticket->judul}\n"
                . "Unit: {$this->ticket->subUnit?->unit?->nama}\n"
                . "Jenis: SLA {$jenisBreachLabel}\n"
                . "Status: {$this->ticket->status}\n\n"
                . ($this->tier >= 3
                    ? "⛔ BREACH! Segera tangani tiket ini."
                    : "Segera tangani tiket ini sebelum eskalasi lebih lanjut."),
        ];
    }
}
