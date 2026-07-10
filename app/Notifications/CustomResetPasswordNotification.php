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
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        $url = url('/reset-password/' . $this->token . '?email=' . urlencode($notifiable->email));

        return (new MailMessage)
            ->subject('Permintaan Reset Password - Halo APU')
            ->greeting('Halo, ' . ($notifiable->username ?? 'Pengguna') . '!')
            ->line('Anda menerima email ini karena kami menerima permintaan reset password untuk akun Anda di sistem Halo APU.')
            ->action('Reset Password', $url)
            ->line('Link reset password ini akan kedaluwarsa dalam 60 menit.')
            ->line('Jika Anda tidak merasa meminta reset password, Anda dapat mengabaikan email ini dan akun Anda akan tetap aman.')
            ->salutation('Salam hormat, Tim Halo APU');
    }
}
