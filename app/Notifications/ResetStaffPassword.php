<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset link for a staff member. It opens the staff web app's reset
 * page, which sends the token back with the new password.
 */
class ResetStaffPassword extends Notification
{
    public function __construct(private string $token)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = config('bank.staff_web_url').'/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->email,
        ]);
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject('Atur ulang password '.config('bank.name'))
            ->greeting('Halo '.$notifiable->full_name.',')
            ->line('Kami menerima permintaan untuk mengatur ulang password akun '.$notifiable->username.'.')
            ->action('Atur ulang password', $url)
            ->line("Tautan berlaku {$minutes} menit dan hanya dapat dipakai sekali.")
            ->line('Jika Anda tidak meminta ini, abaikan email ini; password Anda tidak berubah.');
    }
}
