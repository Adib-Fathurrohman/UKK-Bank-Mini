<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Security notice after activation, so the owner notices if someone else
 * activated their account.
 */
class CustomerActivatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $provider,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Rekening Anda sudah aktif — '.config('bank.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.customer-activated',
            text: 'mail.customer-activated-text',
            with: [
                'method' => $this->provider === 'google.com' ? 'akun Google' : 'email dan password',
            ],
        );
    }
}
