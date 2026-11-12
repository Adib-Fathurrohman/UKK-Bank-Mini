<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Link that proves the staff member owns the email. Opening it verifies the
 * address and sends them to the staff web app's login page.
 */
class VerifyStaffEmail extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $hours = config('bank.staff_email_verify_hours');
        $url = URL::temporarySignedRoute('staff.email.verify', now()->addHours($hours), [
            'user' => $notifiable->id,
            // Ties the link to this address: after a change, an old link no longer verifies.
            'hash' => sha1(strtolower($notifiable->email)),
        ]);

        return (new MailMessage)
            ->subject('Verifikasi email akun '.config('bank.name'))
            ->greeting('Halo '.$notifiable->full_name.',')
            ->line('Email ini didaftarkan untuk akun '.$notifiable->username.' di '.config('bank.name').'. Verifikasi diperlukan agar Anda dapat mengatur ulang password lewat email.')
            ->action('Verifikasi email', $url)
            ->line("Tautan berlaku {$hours} jam. Jika Anda tidak merasa memiliki akun ini, abaikan email ini.");
    }
}
