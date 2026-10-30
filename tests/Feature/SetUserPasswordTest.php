<?php

namespace Tests\Feature;

use App\Console\Commands\SetUserPassword;
use App\Models\User;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\TestCase;

/** php artisan user:set-password: the way in that needs no email. */
class SetUserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const TYPED = 'typed-Secret-4471';

    protected function setUp(): void
    {
        parent::setUp();
        // The command's whole point is to work without mail: nothing may be sent.
        Mail::fake();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
        parent::tearDown();
    }

    public function test_creates_a_new_admin_when_the_email_is_unknown(): void
    {
        $this->artisan('user:set-password')
            ->expectsQuestion('Email', 'Owner@Client.example')
            ->expectsConfirmation('There is no user with the email owner@client.example. Create a new admin account?', 'yes')
            ->expectsQuestion('Name', 'Client Owner')
            ->expectsQuestion('Password', self::TYPED)
            ->expectsQuestion('Repeat the password', self::TYPED)
            ->expectsOutputToContain('Admin account created for owner@client.example.')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame(['Client Owner', 'owner@client.example', 'admin'], [$user->name, $user->email, $user->role]);
        $this->assertTrue(Hash::check(self::TYPED, $user->password));
    }

    public function test_name_can_be_given_as_an_option(): void
    {
        $this->artisan('user:set-password', ['email' => 'owner@client.example', '--name' => 'Client Owner'])
            ->expectsConfirmation('There is no user with the email owner@client.example. Create a new admin account?', 'yes')
            ->expectsQuestion('Password', self::TYPED)
            ->expectsQuestion('Repeat the password', self::TYPED)
            ->assertSuccessful();

        $this->assertSame('Client Owner', User::sole()->name);
    }

    public function test_declining_creates_nothing(): void
    {
        $this->artisan('user:set-password', ['email' => 'typo@client.example'])
            ->expectsConfirmation('There is no user with the email typo@client.example. Create a new admin account?', 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_resets_an_existing_users_password_and_keeps_everything_else(): void
    {
        $user = User::factory()->create(['name' => 'Grace', 'email' => 'grace@example.com', 'role' => 'editor', 'password' => 'old-password']);
        $oldRememberToken = $user->remember_token;

        $this->artisan('user:set-password', ['email' => 'grace@example.com'])
            ->expectsOutputToContain('Setting a new password for Grace <grace@example.com> (editor).')
            ->expectsQuestion('Password', self::TYPED)
            ->expectsQuestion('Repeat the password', self::TYPED)
            ->expectsOutputToContain('Password updated for grace@example.com.')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(1, User::count());
        $this->assertSame(['Grace', 'editor'], [$user->name, $user->role]);
        $this->assertTrue(Hash::check(self::TYPED, $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
        // "Remember me" logins made with the old password stop working.
        $this->assertNotSame($oldRememberToken, $user->remember_token);
    }

    public function test_generate_creates_a_password_that_logs_in(): void
    {
        // --no-interaction: no prompts at all, as from a script; creating is the default answer.
        Artisan::call('user:set-password', ['email' => 'owner@client.example', '--name' => 'Client Owner', '--generate' => true, '--no-interaction' => true]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/^Password: ([A-Za-z0-9]{'.SetUserPassword::GENERATED_LENGTH.'})$/m', $output);
        preg_match('/^Password: (\S+)$/m', $output, $match);
        $this->assertSame(1, substr_count($output, $match[1]), 'The password is shown exactly once.');

        $user = User::sole();
        $this->assertSame('admin', $user->role);
        $this->post('/login', ['email' => 'owner@client.example', 'password' => $match[1]])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_generate_resets_an_existing_user_and_differs_each_time(): void
    {
        User::factory()->create(['email' => 'grace@example.com', 'password' => 'old-password']);

        $passwords = [];
        for ($i = 0; $i < 2; $i++) {
            Artisan::call('user:set-password', ['email' => 'grace@example.com', '--generate' => true, '--no-interaction' => true]);
            preg_match('/^Password: (\S+)$/m', Artisan::output(), $match);
            $passwords[] = $match[1];
        }

        $this->assertNotSame($passwords[0], $passwords[1]);
        $this->assertTrue(Hash::check($passwords[1], User::sole()->password));
    }

    public function test_typed_password_is_asked_hidden_and_never_appears_in_the_output(): void
    {
        // The real console output, with only the answers supplied: records every
        // question asked, so the test can check how the password was asked for.
        $buffer = new BufferedOutput;
        $questions = [];
        $this->app->bind(OutputStyle::class, function () use ($buffer, &$questions) {
            $style = Mockery::mock(OutputStyle::class, [new ArrayInput([]), $buffer])->makePartial();
            $style->shouldReceive('askQuestion')->andReturnUsing(function (Question $question) use (&$questions) {
                $questions[] = $question;

                return $question->getQuestion() === 'Name' ? 'Client Owner' : self::TYPED;
            });
            $style->shouldReceive('confirm')->andReturn(true);

            return $style;
        });

        $this->assertSame(0, Artisan::call('user:set-password', ['email' => 'owner@client.example'], $buffer));

        $asked = collect($questions)->mapWithKeys(fn (Question $q) => [$q->getQuestion() => $q]);
        $this->assertEqualsCanonicalizing(['Name', 'Password', 'Repeat the password'], $asked->keys()->all());
        foreach (['Password', 'Repeat the password'] as $label) {
            $this->assertTrue($asked[$label]->isHidden(), "\"{$label}\" is asked with hidden input.");
            // No fallback: where input can't be hidden, the command fails instead of echoing it.
            $this->assertFalse($asked[$label]->isHiddenFallback(), "\"{$label}\" never falls back to visible input.");
        }
        $this->assertFalse($asked['Name']->isHidden());

        $output = $buffer->fetch();
        $this->assertStringNotContainsString(self::TYPED, $output);
        $this->assertStringContainsString('Admin account created for owner@client.example.', $output);
        // Stored hashed, never as typed.
        $this->assertNotSame(self::TYPED, User::sole()->getRawOriginal('password'));
        $this->assertTrue(Hash::check(self::TYPED, User::sole()->password));
    }

    public function test_mismatched_passwords_change_nothing(): void
    {
        $user = User::factory()->create(['email' => 'grace@example.com', 'password' => 'old-password']);

        $this->artisan('user:set-password', ['email' => 'grace@example.com'])
            ->expectsQuestion('Password', self::TYPED)
            ->expectsQuestion('Repeat the password', 'something-else-123')
            ->expectsOutput('The passwords don\'t match. Nothing was changed.')
            ->assertFailed();

        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
    }

    public function test_a_too_short_password_is_refused(): void
    {
        $this->artisan('user:set-password', ['email' => 'owner@client.example', '--name' => 'Client Owner'])
            ->expectsConfirmation('There is no user with the email owner@client.example. Create a new admin account?', 'yes')
            ->expectsQuestion('Password', 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_non_interactive_runs_need_generate(): void
    {
        User::factory()->create(['email' => 'grace@example.com', 'password' => 'old-password']);

        $this->artisan('user:set-password', ['email' => 'grace@example.com', '--no-interaction' => true])
            ->expectsOutput('No password can be typed in a non-interactive run. Use --generate.')
            ->assertFailed();

        $this->assertTrue(Hash::check('old-password', User::sole()->password));
    }

    public function test_an_invalid_email_is_refused(): void
    {
        $this->artisan('user:set-password', ['email' => 'not-an-email'])->assertFailed();

        $this->assertSame(0, User::count());
    }
}
