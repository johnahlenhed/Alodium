<?php

namespace App\Mail;

use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactSubmissionReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Submission $submission) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // "Reply" in the mail client answers the visitor directly.
            replyTo: [new Address($this->submission->email, $this->submission->name)],
            subject: 'New contact message from '.$this->submission->name,
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.contact-submission');
    }
}
