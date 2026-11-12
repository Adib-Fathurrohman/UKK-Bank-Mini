<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the staff member their password changed, so an unexpected change is noticed.
 */
class StaffPasswordChanged extends Notification
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
        return (new MailMessage)
            ->subject('Password '.config('bank.name').' telah diubah')
            ->greeting('Halo '.$notifiable->full_name.',')
            ->line('Password akun '.$notifiable->username.' baru saja diubah pada '.now()->format('d/m/Y H:i').' WIB. Semua sesi lain telah dikeluarkan.')
            ->line('Jika bukan Anda yang mengubahnya, segera hubungi administrator.');
    }
}
