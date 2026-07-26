<?php

namespace App\Mail;

use App\Models\Alert;
use App\Models\AlertEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AlertStateChangedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Alert $alert,
        public AlertEvent $event,
        public string $deepLink,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('[Lina] %s is %s', $this->alert->name, $this->event->state->value),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.alert-state-changed');
    }
}
