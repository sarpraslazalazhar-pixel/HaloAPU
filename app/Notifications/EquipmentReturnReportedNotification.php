<?php

namespace App\Notifications;

use App\Channels\FcmChannel;
use App\Models\Ticket;
use App\Services\EquipmentLoanService;
use App\Traits\FilterNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class EquipmentReturnReportedNotification extends Notification implements ShouldBroadcast
{
    use Queueable, FilterNotificationChannels;

    public Ticket $ticket;

    public string $reporterName;

    public ?string $catatan;

    public function __construct(Ticket $ticket, string $reporterName, ?string $catatan = null)
    {
        $this->ticket = $ticket;
        $this->reporterName = $reporterName;
        $this->catatan = $catatan !== null && trim($catatan) !== '' ? trim($catatan) : null;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast', WebPushChannel::class];
        if (! empty($notifiable->fcm_token)) {
            $channels[] = FcmChannel::class;
        }

        return $this->filterChannels($channels, $notifiable);
    }

    private function buildTitle(): string
    {
        return "Laporan Pengembalian Alat (#TKT-{$this->ticket->formatted_id})";
    }

    private function buildMessage(): string
    {
        $summary = EquipmentLoanService::extractLoanSummary($this->ticket);
        $alatText = $summary['alat_text'] ?? '-';
        $alatInfo = $alatText !== '-' ? " ({$alatText})" : '';
        $catatanInfo = $this->catatan !== null
            ? ' Catatan: "' . Str::limit($this->catatan, 100) . '".'
            : '';

        return "{$this->reporterName} melaporkan alat{$alatInfo} sudah dikembalikan.{$catatanInfo} Mohon cek fisik & lakukan konfirmasi pengembalian.";
    }

    private function buildUrl(): string
    {
        return url('/admin/tiket/' . $this->ticket->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $url = $this->buildUrl();

        return [
            'type' => 'equipment_return_reported',
            'ticket_id' => $this->ticket->id,
            'title' => $this->buildTitle(),
            'message' => $this->buildMessage(),
            'url' => $url,
            'aksi_url' => $url,
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $url = $this->buildUrl();

        return new BroadcastMessage([
            'type' => 'equipment_return_reported',
            'ticket_id' => $this->ticket->id,
            'title' => $this->buildTitle(),
            'message' => $this->buildMessage(),
            'url' => $url,
            'aksi_url' => $url,
        ]);
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        $url = $this->buildUrl();

        return (new WebPushMessage)
            ->title($this->buildTitle())
            ->icon('/images/logo.png')
            ->body($this->buildMessage())
            ->action('Cek & Konfirmasi', $url)
            ->data(['url' => $url]);
    }

    /**
     * @return array<string, string>
     */
    public function toFcm(object $notifiable): array
    {
        $url = $this->buildUrl();

        return [
            'type' => 'equipment_return_reported',
            'ticket_id' => (string) $this->ticket->id,
            'title' => $this->buildTitle(),
            'body' => $this->buildMessage(),
            'url' => $url,
            'aksi_url' => $url,
        ];
    }
}
