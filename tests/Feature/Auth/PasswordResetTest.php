<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_link_email_is_actually_sent_through_the_mailer(): void
    {
        // The real mail path (array transport), not Notification::fake(): the
        // failure below happens inside this path.
        $user = User::factory()->create();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertSame($user->email, $messages[0]->getEnvelope()->getRecipients()[0]->getAddress());
        $this->assertStringContainsString('/reset-password/', $messages[0]->toString());
    }

    public function test_reset_link_request_shows_an_error_instead_of_a_500_when_mail_fails(): void
    {
        $this->useFailingMailer();
        Log::spy();
        $user = User::factory()->create();

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHasErrors(['email' => "The reset email couldn't be sent. Please try again later, or contact the site's administrator."]);

        Log::shouldHaveReceived('error')->once()->withArgs(fn ($message, $context) => $message === 'Password reset email failed.'
            && str_contains($context['error'], 'API key is invalid'));
        // No token is left behind for an email nobody received.
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_a_retry_after_a_mail_failure_is_sent_not_throttled(): void
    {
        $this->useFailingMailer();
        $user = User::factory()->create();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');

        // Mail fixed: trying again straight away sends the link.
        config(['mail.default' => 'array']);
        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    /** A mail transport that fails like Resend with a missing or invalid API key. */
    private function useFailingMailer(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Request to the Resend API failed. Reason: API key is invalid');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }
}
