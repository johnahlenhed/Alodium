<?php

namespace Tests\Feature;

use App\Mail\UserInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class UserCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Ada Admin']);
    }

    private function create(array $data = []): TestResponse
    {
        return $this->actingAs($this->admin)->post('/users', [
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
            'role' => 'editor',
            ...$data,
        ]);
    }

    /** The invitation sent to grace@example.com (fails the test if there is none). */
    private function sentInvitation(): UserInvitation
    {
        $sent = Mail::sent(UserInvitation::class, fn ($mail) => $mail->hasTo('grace@example.com'));
        $this->assertCount(1, $sent);

        return $sent->first();
    }

    public function test_admin_can_create_a_user_and_an_invitation_is_sent(): void
    {
        Mail::fake();
        Notification::fake();

        $this->create()
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'An invitation email has been sent to grace@example.com'));

        $user = User::where('email', 'grace@example.com')->firstOrFail();
        $this->assertSame('Grace Hopper', $user->name);

        $invitation = $this->sentInvitation();
        $this->assertTrue($invitation->user->is($user));
        $this->assertTrue($invitation->invitedBy->is($this->admin));
        $this->assertStringStartsWith(url('/reset-password/'), $invitation->resetUrl);
        $this->assertStringContainsString('email=grace%40example.com', $invitation->resetUrl);

        // The custom invitation replaces Laravel's "password reset request" email.
        Notification::assertNotSentTo($user, ResetPassword::class);
    }

    public function test_created_user_has_the_chosen_role(): void
    {
        Mail::fake();

        $this->create(['email' => 'ed@example.com', 'role' => 'editor']);
        $this->create(['email' => 'ad@example.com', 'role' => 'admin']);

        $this->assertSame('editor', User::where('email', 'ed@example.com')->value('role'));
        $this->assertSame('admin', User::where('email', 'ad@example.com')->value('role'));
    }

    public function test_the_temporary_password_is_random_and_hashed(): void
    {
        Mail::fake();

        $this->create();

        $password = User::where('email', 'grace@example.com')->value('password');
        $this->assertTrue(Hash::isHashed($password));
        $this->assertFalse(Hash::check('password', $password));
    }

    public function test_the_invitation_link_lets_the_new_user_set_a_password_and_log_in(): void
    {
        Mail::fake();
        $this->create();
        $user = User::where('email', 'grace@example.com')->firstOrFail();

        // Pull the token out of the link, as the reset page would.
        $url = $this->sentInvitation()->resetUrl;
        $token = basename(parse_url($url, PHP_URL_PATH));
        auth()->logout();

        $this->get($url)->assertOk();
        $this->post('/reset-password', [
            'token' => $token,
            'email' => 'grace@example.com',
            'password' => 'my-new-password',
            'password_confirmation' => 'my-new-password',
        ])->assertSessionHasNoErrors();

        $this->post('/login', ['email' => 'grace@example.com', 'password' => 'my-new-password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_invitation_email_content(): void
    {
        $user = User::factory()->editor()->make(['name' => 'Grace <b>Hopper</b>', 'email' => 'grace@example.com']);
        $mail = new UserInvitation($user, 'https://admin.example.com/reset-password/abc?email=grace%40example.com', $this->admin);

        $mail->assertHasSubject("You've been invited to Alodium");
        $mail->assertSeeInHtml('Ada Admin has invited you to manage content on Alodium', false);
        $mail->assertSeeInHtml('Set up your password');
        $mail->assertSeeInHtml('https://admin.example.com/reset-password/abc?email=grace%40example.com', false);
        $mail->assertSeeInHtml('expires in 60 minutes');
        $mail->assertSeeInHtml('The Alodium team');
        $mail->assertDontSeeInHtml('<b>Hopper</b>', false); // names are escaped
        $mail->assertSeeInText('Set up your password');
    }

    public function test_duplicate_email_returns_a_validation_error(): void
    {
        Mail::fake();

        $this->create(['email' => $this->admin->email])->assertSessionHasErrors('email');

        $this->assertSame(1, User::count());
        Mail::assertNothingSent();
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->create(['name' => '', 'email' => 'not-an-email', 'role' => 'owner'])
            ->assertSessionHasErrors(['name', 'email', 'role']);
    }

    public function test_editors_cannot_create_users(): void
    {
        Mail::fake();
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->post('/users', [
            'name' => 'X', 'email' => 'x@example.com', 'role' => 'admin',
        ])->assertForbidden();

        $this->assertNull(User::where('email', 'x@example.com')->first());
        Mail::assertNothingSent();
    }

    public function test_user_is_still_created_with_a_warning_when_the_email_fails(): void
    {
        Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Resend unreachable'));

        $this->create()
            ->assertRedirect(route('users.index'))
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'welcome email failed to send')
                && str_contains($message, route('password.request')));

        $this->assertNotNull(User::where('email', 'grace@example.com')->first());
    }

    public function test_warning_flash_is_shared_with_the_page(): void
    {
        $this->actingAs($this->admin)
            ->withSession(['warning' => 'Heads up'])
            ->get('/users')
            ->assertInertia(fn ($page) => $page->where('flash.warning', 'Heads up'));
    }
}
