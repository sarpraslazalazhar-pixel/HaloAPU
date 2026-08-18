<?php

namespace App\Notifications;

use App\Channels\WhatsAppChannel;
use App\Channels\FcmChannel;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

use App\Traits\FilterNotificationChannels;

class TicketCreatedAdminNotification extends Notification implements ShouldBroadcast
{
    use Queueable, FilterNotificationChannels;

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
            $channels[] = 'broadcast';
            $channels[] = \NotificationChannels\WebPush\WebPushChannel::class;
            if (!empty($notifiable->fcm_token)) {
                $channels[] = FcmChannel::class;
            }
            // Jika admin punya nomor WA, kirim juga via WhatsApp
            if (!empty($notifiable->no_wa)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        if ($notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable) {
            // Fallback: kirim ke nomor_wa_utama jika ada
            $nomorUtama = \App\Models\SystemConfig::getValue('nomor_wa_utama');
            if (!empty($nomorUtama)) {
                $channels[] = WhatsAppChannel::class;
            }
        }

        return $this->filterChannels($channels, $notifiable);
    }

    public function toFcm(object $notifiable): array
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? 'Umum';
        $pembuat = $this->ticket->user->name ?: $this->ticket->user->username;

        return [
            'title' => 'Tiket Baru Masuk #' . $this->ticket->id,
            'body' => "Tiket baru dari {$pembuat} ({$layanan})",
            'ticket_id' => (string) $this->ticket->id,
            'url' => url('/admin/tiket/' . $this->ticket->id),
            'type' => 'ticket_created',
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     * Ini yang dikirim via Echo ke browser untuk real-time notification + suara.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $pembuat = $this->ticket->user->name ?: $this->ticket->user->username;

        return new BroadcastMessage([
            'type' => 'ticket_created',
            'ticket_id' => $this->ticket->id,
            'title' => 'Tiket Baru Masuk',
            'message' => "Ada tiket baru dari {$pembuat} terkait layanan {$layanan}.",
            'url' => url('/admin/tiket/' . $this->ticket->id),
        ]);
    }

    public function toWebPush($notifiable, $notification)
    {
        $layanan = $this->ticket->subUnit->nama_layanan ?? '-';
        $pembuat = $this->ticket->user->name ?: $this->ticket->user->username;
        return (new \NotificationChannels\WebPush\WebPushMessage)
            ->title('Tiket Baru Masuk')
            ->icon('/images/logo.png')
            ->body("Ada tiket baru dari {$pembuat} terkait layanan {$layanan}.")
            ->action('Lihat Tiket', url('/admin/tiket/' . $this->ticket->id))
            ->data(['url' => url('/admin/tiket/' . $this->ticket->id)]);
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
        
        $namaAdmin = $notifiable->name ?? ($notifiable->nama ?? 'Admin');

        $message = "Halo *{$namaAdmin}* 👋\n\n";
        $message .= "Ada info baru nih. Pengajuan *{$layanan}* dari *{$pembuat}* udah terdaftar di sistem ticketing kita ya 😊\n\n";
        $message .= "Biar lebih jelas, langsung aja cek detailnya di sini:\n{$url}\n\n";
        $message .= "Terima kasih";

        // Tentukan nomor penerima
        if ($notifiable instanceof \App\Models\Admin) {
            $receiver = $notifiable->no_wa;
        } else {
            // AnonymousNotifiable → ambil nomor_wa_utama
            $receiver = \App\Models\SystemConfig::getValue('nomor_wa_utama');
        }

        return [
            'receiver' => $receiver,
            'message' => $message,
        ];
    }
}

