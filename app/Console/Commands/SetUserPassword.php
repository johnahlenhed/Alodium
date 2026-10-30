<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Sets a user's password from the server's command line, creating the user as
 * an admin if the email is new. For the first admin on a fresh install, and for
 * getting someone in when the invitation or password reset email can't be
 * delivered: it never sends mail, so it works whatever state mail is in.
 *
 * The password is never an argument, so it isn't in the shell history or the
 * process list (unlike the old tinker snippet, which also left it in psysh's
 * history). It is either typed at a hidden prompt or generated and shown once.
 */
class SetUserPassword extends Command
{
    protected $signature = 'user:set-password
        {email? : The user\'s email address (asked for if left out)}
        {--name= : Name for a new user (asked for if left out)}
        {--generate : Generate a strong random password and show it once, instead of typing one}';

    protected $description = 'Set a user\'s password, creating them as an admin if they don\'t exist. Sends no email.';

    /** Length of a --generate password. Letters and digits only, so it survives copying into chat or a password manager. */
    public const GENERATED_LENGTH = 24;

    public function handle(): int
    {
        $email = Str::lower(trim((string) ($this->argument('email') ?? $this->ask('Email'))));
        if ($error = $this->invalid(['email' => $email], ['email' => ['required', 'email', 'max:255']])) {
            $this->error($error);

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        $name = null;
        if ($user) {
            $this->line("Setting a new password for {$user->name} <{$user->email}> ({$user->role}).");
        } else {
            // A typo in the email would otherwise create a second admin without a word.
            if (! $this->confirm("There is no user with the email {$email}. Create a new admin account?", true)) {
                $this->line('Nothing was changed.');

                return self::SUCCESS;
            }
            $name = trim((string) ($this->option('name') ?? $this->ask('Name')));
            if ($error = $this->invalid(['name' => $name], ['name' => ['required', 'string', 'max:255']])) {
                $this->error($error);

                return self::FAILURE;
            }
        }

        $password = $this->option('generate') ? Str::password(self::GENERATED_LENGTH, symbols: false) : $this->typedPassword();
        if ($password === null) {
            return self::FAILURE;
        }

        if ($user) {
            // A new remember token logs out "remember me" logins made with the old
            // password, as Laravel's own password reset does.
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $this->info("Password updated for {$user->email}.");
        } else {
            // The role explicitly, not the column default (ARCHITECTURE.md section 12, "Role default").
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => 'admin']);
            $this->info("Admin account created for {$user->email}.");
        }

        if ($this->option('generate')) {
            $this->newLine();
            $this->line("Password: {$password}");
            $this->newLine();
            $this->warn('Shown only this once; it is stored hashed. Hand it over securely (for example a password manager share), and ask them to change it under Profile after logging in.');
        }

        $this->line('Log in at '.route('login'));

        return self::SUCCESS;
    }

    /**
     * Asks twice at hidden prompts. The fallback is off: where the terminal can't
     * hide input, Symfony would otherwise ask again with the typing visible, so
     * this fails instead. Input piped in (for example from a password manager's
     * CLI) is read as it is: nothing is typed at the terminal, so nothing echoes.
     * Null (after an error message) if no valid password was given.
     */
    private function typedPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            $this->error('No password can be typed in a non-interactive run. Use --generate.');

            return null;
        }

        $password = (string) $this->secret('Password', false);
        if ($error = $this->invalid(['password' => $password], ['password' => ['required', Password::defaults()]])) {
            $this->error($error);

            return null;
        }
        if ((string) $this->secret('Repeat the password', false) !== $password) {
            $this->error('The passwords don\'t match. Nothing was changed.');

            return null;
        }

        return $password;
    }

    /** The first validation error, or null. The same rules as the admin's forms. */
    private function invalid(array $data, array $rules): ?string
    {
        $validator = Validator::make($data, $rules);

        return $validator->fails() ? $validator->errors()->first() : null;
    }
}
