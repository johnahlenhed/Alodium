<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when an admin creates an account on the Users page. The link is a normal
 * Laravel password reset link, so the standard reset page and token expiry apply.
 */
class UserInvitation extends Mailable
{
    use Queueable, SerializesModels;

    /** How long the link works (Laravel's password reset expiry). */
    public int $expiresInMinutes;

    public function __construct(
        public User $user,
        public string $resetUrl,
        public User $invitedBy,
    ) {
        $this->expiresInMinutes = (int) config('auth.passwords.users.expire');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You've been invited to Alodium");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.user-invitation');
    }
}
