<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomResetPasswordNotification extends Notification
{
    use Queueable;

    public $token;

    public function __construct($token)
    {
        $this->token = $token;
    }

    public function via($notifiable)
    {
        $channels = ['mail'];
        if (!empty($notifiable->no_wa)) {
            $channels[] = \App\Channels\WhatsAppChannel::class;
        }
        return $channels;
    }

    public function toWhatsApp($notifiable)
    {
        $url = url('/reset-password/' . $this->token . '?email=' . urlencode($notifiable->email));
        $name = $notifiable->name ?? $notifiable->username ?? 'Pengguna';
        return [
            'receiver' => $notifiable->no_wa,
            'message' => "*Permintaan Reset Password - Halo APU*\n\nHalo, {$name}!\n\nKami menerima permintaan reset password untuk akun kamu.\nSilakan klik tautan berikut untuk membuat password baru:\n{$url}\n\nTautan ini berlaku selama 60 menit.\nAbaikan pesan ini jika kamu tidak merasa meminta reset password.",
        ];
    }

    public function toMail($notifiable)
    {
        $url = url('/reset-password/' . $this->token . '?email=' . urlencode($notifiable->email));

        return (new MailMessage)
            ->subject('Permintaan Reset Password - Halo APU')
            ->greeting('Halo, ' . ($notifiable->username ?? 'Pengguna') . '!')
            ->line('Kamu menerima email ini karena kami menerima permintaan reset password untuk akun kamu di sistem Halo APU.')
            ->action('Reset Password', $url)
            ->line('Link reset password ini akan kedaluwarsa dalam 60 menit.')
            ->line('Jika kamu tidak merasa meminta reset password, kamu dapat mengabaikan email ini dan akun kamu akan tetap aman.')
            ->salutation('Salam hormat, Tim Halo APU');
    }
}
