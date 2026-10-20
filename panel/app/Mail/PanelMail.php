<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A plain-text message from the panel: notifications, test messages and
 * administrator invitations.
 */
class PanelMail extends Mailable
{
    use Queueable;

    public function __construct(public string $title, public string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.panel');
    }
}
