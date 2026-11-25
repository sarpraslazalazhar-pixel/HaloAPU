<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use App\Channels\WhatsAppChannel;
use App\Models\AiBriefing;
use App\Models\ReminderConfig;
use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class AiOperationalBriefingNotification extends Notification
{
    use FilterNotificationChannels, Queueable, SerializesModels;

    public function __construct(
        protected AiBriefing $briefing
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $jenis = $this->briefing->period_type === 'weekly'
            ? 'ai_briefing_weekly'
            : 'ai_briefing_daily';

        $config = ReminderConfig::where('jenis_reminder', $jenis)->first();
        $channels = ['database'];

        if (! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        if ($config && is_array($config->channel_aktif)) {
            if (in_array('email', $config->channel_aktif, true) && ! empty($notifiable->email)) {
                $channels[] = 'mail';
            }
            if (in_array('whatsapp', $config->channel_aktif, true) && ! empty($notifiable->no_wa)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $this->filterChannels($channels, $notifiable);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $summaryLine = $this->buildSummaryLine();
        $actionUrl = "/admin/ai?briefing={$this->briefing->id}";

        return [
            'type' => 'ai_briefing',
            'briefing_id' => (int) $this->briefing->id,
            'period_type' => (string) $this->briefing->period_type,
            'scope_label' => (string) $this->briefing->scope_label,
            'title' => (string) $this->briefing->title,
            'message' => $summaryLine,
            'judul' => (string) $this->briefing->title,
            'pesan' => $summaryLine,
            'icon' => 'sparkles',
            'aksi_url' => $actionUrl,
            'url' => $actionUrl,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $namaAdmin = $notifiable->name ?? ($notifiable->username ?? 'Admin');
        $snapshot = is_array($this->briefing->metrics_snapshot) ? $this->briefing->metrics_snapshot : [];
        $actionUrl = url("/admin/ai?briefing={$this->briefing->id}");

        $newTickets = (int) ($snapshot['new_tickets'] ?? 0);
        $solvedTickets = (int) ($snapshot['solved_tickets'] ?? 0);
        $activeTotal = (int) ($snapshot['active_open'] ?? 0)
            + (int) ($snapshot['active_on_progress'] ?? 0)
            + (int) ($snapshot['active_pending'] ?? 0);
        $slaAtRisk = (int) ($snapshot['sla_at_risk_count'] ?? 0);
        $slaBreached = (int) ($snapshot['sla_breached_count'] ?? 0);

        return (new MailMessage)
            ->subject((string) $this->briefing->title)
            ->greeting("Halo, {$namaAdmin}!")
            ->line("Berikut ringkasan briefing operasional HaloAPU untuk cakupan **{$this->briefing->scope_label}**:")
            ->line("**Tiket Masuk:** {$newTickets} | **Tiket Selesai:** {$solvedTickets} | **Tiket Aktif:** {$activeTotal}")
            ->line("**Status SLA:** {$slaAtRisk} tiket berisiko ({$slaBreached} breach) | **Pending > 2 Hari:** " . (int) ($snapshot['stale_pending_gt_2_days'] ?? 0))
            ->action('Lihat Briefing Lengkap di HaloAPU AI', $actionUrl)
            ->line('Terima kasih.');
    }

    /**
     * @return array{receiver: mixed, message: string}
     */
    public function toWhatsApp(object $notifiable): array
    {
        $namaAdmin = $notifiable->name ?? ($notifiable->username ?? 'Admin');
        $url = url("/admin/ai?briefing={$this->briefing->id}");
        $summaryLine = $this->buildSummaryLine();

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "*{$this->briefing->title}*\n";
        $message .= "Cakupan: {$this->briefing->scope_label}\n\n";
        $message .= "{$summaryLine}\n\n";
        $message .= "Baca analisis & rekomendasi lengkapnya di HaloAPU AI:\n{$url}";

        return [
            'receiver' => $notifiable->no_wa,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toFcm(object $notifiable): array
    {
        $data = $this->toDatabase($notifiable);

        return [
            'title' => $data['title'],
            'body' => $data['message'],
            'url' => url($data['url']),
            'type' => 'ai_briefing',
            'data' => [
                'briefing_id' => (string) $this->briefing->id,
                'period_type' => (string) $this->briefing->period_type,
            ],
        ];
    }

    private function buildSummaryLine(): string
    {
        $snapshot = is_array($this->briefing->metrics_snapshot) ? $this->briefing->metrics_snapshot : [];
        $newTickets = (int) ($snapshot['new_tickets'] ?? 0);
        $solvedTickets = (int) ($snapshot['solved_tickets'] ?? 0);
        $activeTotal = (int) ($snapshot['active_open'] ?? 0)
            + (int) ($snapshot['active_on_progress'] ?? 0)
            + (int) ($snapshot['active_pending'] ?? 0);
        $slaAtRisk = (int) ($snapshot['sla_at_risk_count'] ?? 0);
        $slaBreached = (int) ($snapshot['sla_breached_count'] ?? 0);

        return "Tiket masuk: {$newTickets}, selesai: {$solvedTickets}, aktif: {$activeTotal} (SLA berisiko: {$slaAtRisk}, breach: {$slaBreached}).";
    }
}
