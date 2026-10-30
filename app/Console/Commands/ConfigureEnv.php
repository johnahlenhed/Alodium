<?php

namespace App\Console\Commands;

use Closure;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fills in the .env values that have no other home: the secrets the README used
 * to have generated with openssl and pasted in, and the service credentials only
 * a person can supply. Everything is written in one pass, then config:cache runs
 * once.
 *
 * A value that is already set is never replaced, so running it again is safe and
 * only asks for what is still missing. That matters most for the backup
 * passphrase: a new one can't decrypt the off-server backups made with the old.
 *
 * Not here, because something else sets them: APP_KEY (app:ensure-key), the
 * MaxMind key (deploy/add-domain.sh geoip), admin accounts (user:set-password),
 * and the values that depend on the server and its domains, which the README's
 * step 3 has you set by hand (APP_URL, PUBLIC_SITE_URL, MAIL_FROM_ADDRESS, ...).
 */
class ConfigureEnv extends Command
{
    protected $signature = 'app:configure-env';

    protected $description = 'Fill in .env secrets (generated) and service credentials (asked for), keeping every value already set, then cache the config once';

    /** The values in .env now, as Laravel reads them. */
    private array $current = [];

    /** What this run writes: name => value. */
    private array $changes = [];

    /** One line per value for the summary: [name, what happened]. */
    private array $summary = [];

    /** Set when the database password was generated: the SQL that gives the user that password. */
    private ?string $databaseSql = null;

    public function handle(): int
    {
        // The contract type of $laravel lacks environmentFilePath(); the application always has it.
        /** @var Application $app */
        $app = $this->laravel;
        $path = $app->environmentFilePath();
        if (! is_file($path)) {
            $this->error("{$path} not found. Create it first: cp .env.example .env");

            return self::FAILURE;
        }
        $contents = (string) file_get_contents($path);
        // Fresh state: Artisan reuses the command object when it runs twice in one process.
        $this->current = Dotenv::parse($contents);
        $this->changes = [];
        $this->summary = [];
        $this->databaseSql = null;

        $this->generatedSecrets();
        $this->database();
        $this->mail();
        $this->sentry();
        $this->offsiteBackups();

        if ($this->changes !== []) {
            $updated = $this->withChanges($contents);
            if ($updated === null) {
                return self::FAILURE;
            }
            file_put_contents($path, $updated); // in place: .env keeps its owner and mode
        }

        $this->newLine();
        $this->table(['Variable', 'Result'], $this->summary);
        if ($this->changes === []) {
            $this->line('Nothing to write: every value was already set or skipped.');
        }

        // Once, at the end, so every value above takes effect together.
        $this->call('config:cache');

        $this->reminders();

        return self::SUCCESS;
    }

    /** README step 9 (webhook) and step 10 (backup encryption): no person needed. */
    private function generatedSecrets(): void
    {
        // The same as the README's "openssl rand -hex 32" and "openssl rand -base64 32".
        $this->generate('WEBHOOK_SECRET', fn () => bin2hex(random_bytes(32)));
        $this->generate('BACKUP_ENCRYPTION_PASSPHRASE', fn () => base64_encode(random_bytes(32)));
    }

    private function database(): void
    {
        $connection = $this->value('DB_CONNECTION') ?: 'mysql';
        if ($connection === 'sqlite') {
            $this->summary[] = ['DB_*', 'not needed (DB_CONNECTION is sqlite)'];

            return;
        }

        foreach (['DB_DATABASE' => 'Database name', 'DB_USERNAME' => 'Database user'] as $name => $label) {
            if ($this->keep($name)) {
                continue;
            }
            // Plain MySQL names only: they also go into the SQL printed below.
            while (! preg_match('/^[A-Za-z0-9_]{1,32}$/', $answer = trim((string) $this->ask($label, 'alodium')))) {
                $this->error('Use letters, digits and _ only (at most 32).');
            }
            $this->set($name, $answer);
        }

        if ($this->keep('DB_PASSWORD')) {
            return;
        }
        $password = $this->input->isInteractive()
            ? $this->askUntilValid('Database password (leave empty to generate one)', true, fn ($value) => $this->encode($value) === null
                ? 'It contains both \' and one of " \\ $, which .env cannot hold. Choose another.'
                : null)
            : null;

        if ($password === null) {
            $password = Str::password(32, symbols: false);
            $user = $this->changes['DB_USERNAME'] ?? $this->value('DB_USERNAME');
            $database = $this->changes['DB_DATABASE'] ?? $this->value('DB_DATABASE');
            // The README's SQL (deployment step 1), with this password. The mysql client
            // leaves statements with IDENTIFIED out of its history by default.
            $this->databaseSql = "CREATE USER '{$user}'@'localhost' IDENTIFIED BY '{$password}';\n"
                ."GRANT ALL PRIVILEGES ON {$database}.* TO '{$user}'@'localhost';\n"
                .'FLUSH PRIVILEGES;';
            $this->set('DB_PASSWORD', $password, 'generated (create the database user with the SQL below)');

            return;
        }
        $this->set('DB_PASSWORD', $password);
    }

    private function mail(): void
    {
        if ($this->keep('RESEND_API_KEY')) {
            return;
        }
        $key = $this->askUntilValid('Resend API key (starts with re_; leave empty to skip)', true,
            fn ($value) => preg_match('/^re_[A-Za-z0-9_]+$/', $value) ? null : 'That doesn\'t look like a Resend API key: they start with re_ and have no spaces.');

        $key === null
            ? $this->summary[] = ['RESEND_API_KEY', 'not set: invitations, password resets and contact form emails can\'t be sent until it is (README, Email (Resend)). php artisan user:set-password works without it.']
            : $this->set('RESEND_API_KEY', $key);
    }

    private function sentry(): void
    {
        if ($this->keep('SENTRY_LARAVEL_DSN')) {
            return;
        }
        $dsn = $this->askUntilValid('Sentry DSN (https://<key>@<org>.ingest.sentry.io/<project>; leave empty to skip)', false,
            fn ($value) => filter_var($value, FILTER_VALIDATE_URL) && str_starts_with($value, 'https://') && str_contains($value, '@')
                ? null
                : 'That doesn\'t look like a Sentry DSN: copy it from the project\'s Client Keys (DSN) page.');

        $dsn === null
            ? $this->summary[] = ['SENTRY_LARAVEL_DSN', 'not set: errors are not reported to Sentry (optional, README step 11)']
            : $this->set('SENTRY_LARAVEL_DSN', $dsn);
    }

    /** README step 10. Optional: without a bucket, backups stay on the server. */
    private function offsiteBackups(): void
    {
        $required = ['BACKUP_S3_KEY', 'BACKUP_S3_SECRET', 'BACKUP_S3_BUCKET'];
        $missing = array_filter($required, fn ($name) => ! $this->isSet($name));
        if ($missing === []) {
            foreach ($required as $name) {
                $this->keep($name);
            }

            return;
        }

        // Without a terminal, confirm() gives its default (no), so the required questions below are never asked.
        if (! $this->input->isInteractive() || ! $this->confirm('Set up off-server backups to an S3-compatible bucket now? (README step 10; you can run this command again later)', false)) {
            $this->summary[] = ['BACKUP_S3_*', 'not set: database backups and media stay on this server only. Off-server copies won\'t work until these are configured (README step 10; run php artisan app:configure-env again).'];

            return;
        }

        $fields = ['BACKUP_S3_KEY' => ['Access key ID', false], 'BACKUP_S3_SECRET' => ['Secret access key', true], 'BACKUP_S3_BUCKET' => ['Bucket name', false]];
        foreach ($fields as $name => [$label, $hidden]) {
            if ($this->keep($name)) {
                continue;
            }
            // Required once off-server backups are chosen: without all three nothing is uploaded.
            $value = null;
            while ($value === null) {
                $value = $this->askUntilValid($label, $hidden, fn () => null);
                if ($value === null) {
                    $this->error("{$label} is needed for off-server backups.");
                }
            }
            $this->set($name, $value);
        }

        if (! $this->keep('BACKUP_S3_ENDPOINT')) {
            $endpoint = $this->askUntilValid('Endpoint, e.g. https://<account-id>.r2.cloudflarestorage.com (copy it from the bucket settings; leave empty for AWS S3)', false,
                fn ($value) => filter_var($value, FILTER_VALIDATE_URL) && str_starts_with($value, 'https://') ? null : 'The endpoint is a full https:// address.');
            $endpoint === null
                ? $this->summary[] = ['BACKUP_S3_ENDPOINT', 'empty: AWS S3 itself']
                : $this->set('BACKUP_S3_ENDPOINT', $endpoint);
        }
        if (! $this->keep('BACKUP_S3_REGION')) {
            $this->set('BACKUP_S3_REGION', (string) $this->ask('Region (auto for Cloudflare R2)', 'auto'));
        }
    }

    private function reminders(): void
    {
        if ($this->databaseSql !== null) {
            $this->newLine();
            $this->warn('Create the database user with the generated password. Run this once as MySQL\'s root user (sudo mysql):');
            $this->line($this->databaseSql);
        }
        if (isset($this->changes['BACKUP_ENCRYPTION_PASSPHRASE'])) {
            $this->newLine();
            $this->warn('Copy BACKUP_ENCRYPTION_PASSPHRASE from .env into a password manager now. Without it, off-server backups can\'t be restored if the server is lost (README step 10).');
        }
    }

    // ---------------------------------------------------------------- helpers

    private function value(string $name): string
    {
        return trim((string) ($this->current[$name] ?? ''));
    }

    private function isSet(string $name): bool
    {
        return $this->value($name) !== '' || isset($this->changes[$name]);
    }

    /** True (and noted) if the value is already set, so it is left alone. */
    private function keep(string $name): bool
    {
        if ($this->value($name) === '') {
            return false;
        }
        $this->summary[] = [$name, 'kept (already set)'];

        return true;
    }

    private function generate(string $name, Closure $make): void
    {
        if (! $this->keep($name)) {
            $this->set($name, $make(), 'generated');
        }
    }

    private function set(string $name, string $value, string $note = 'set'): void
    {
        $this->changes[$name] = $value;
        $this->summary[] = [$name, $note];
    }

    /**
     * Asks until the answer passes $validate (which returns an error message or
     * null). Null for an empty answer, or when nobody can answer (--no-interaction).
     * Hidden questions have no visible fallback, as in user:set-password.
     */
    private function askUntilValid(string $question, bool $hidden, Closure $validate): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }
        while (true) {
            $answer = trim((string) ($hidden ? $this->secret($question, false) : $this->ask($question)));
            if ($answer === '') {
                return null;
            }
            if (($error = $validate($answer)) === null) {
                return $answer;
            }
            $this->error($error);
        }
    }

    /**
     * $contents with each changed variable's line replaced (the first, as Laravel
     * reads the first) or added at the end. Null, with an error, if the result
     * doesn't read back as the values intended; nothing is written then.
     */
    private function withChanges(string $contents): ?string
    {
        foreach ($this->changes as $name => $value) {
            $line = $name.'='.$this->encode($value);
            $pattern = '/^'.preg_quote($name, '/').'=.*$/m';
            if (preg_match($pattern, $contents)) {
                $contents = preg_replace_callback($pattern, fn () => $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
            }
        }

        try {
            $parsed = Dotenv::parse($contents);
        } catch (Throwable) {
            $parsed = [];
        }
        foreach ($this->changes as $name => $value) {
            if (($parsed[$name] ?? null) !== $value) {
                $this->error("Couldn't write {$name} to .env so that it reads back correctly. Nothing was written.");

                return null;
            }
        }

        return $contents;
    }

    /**
     * The value as .env needs it: bare when safe, otherwise quoted (double quotes
     * unless it has " \ or $, which phpdotenv treats specially there; then single
     * quotes, which take everything literally except '). Null if neither works.
     */
    private function encode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_.:\/@-]*$/', $value)) {
            return $value;
        }
        if (str_contains($value, "\n")) {
            return null;
        }
        if (! preg_match('/["\\\\$]/', $value)) {
            return '"'.$value.'"';
        }

        return str_contains($value, "'") ? null : "'".$value."'";
    }
}
