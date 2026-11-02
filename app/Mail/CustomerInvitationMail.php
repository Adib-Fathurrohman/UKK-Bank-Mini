<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * The activation link for a new account (or a new link replacing an old one).
 */
class CustomerInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $activationUrl,
        public Carbon $expiresAt,
        public bool $isResend = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: ($this->isResend ? 'Tautan aktivasi baru — ' : 'Aktifkan rekening Anda — ').config('bank.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.customer-invitation',
            text: 'mail.customer-invitation-text',
        );
    }
}
