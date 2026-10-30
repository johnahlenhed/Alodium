<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * `composer setup`'s key step. Laravel's key:generate replaces APP_KEY without
 * asking outside production, so re-running setup on an existing install would
 * log everyone out and make everything encrypted with the old key unreadable
 * (README, Disaster recovery). This only generates a key when .env has none.
 *
 * It reads the .env file itself, not config('app.key'), which may be cached or
 * come from a real environment variable.
 */
class EnsureAppKey extends Command
{
    protected $signature = 'app:ensure-key';

    protected $description = 'Generate APP_KEY only if .env doesn\'t have one yet (used by composer setup)';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();

        if (! $this->hasKey($path)) {
            // A fresh install: exactly what composer setup did before.
            return $this->call('key:generate');
        }

        $this->components->warn('APP_KEY is already set in '.basename($path).', so key generation was skipped.');
        $this->line('  Replacing it would log every user out and make anything encrypted with the');
        $this->line('  old key unreadable. To replace it on purpose, run: php artisan key:generate');

        return self::SUCCESS;
    }

    /** Whether the file has a non-empty APP_KEY line. Quotes alone ("") count as empty. */
    private function hasKey(string $path): bool
    {
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        if (! preg_match('/^APP_KEY=(.*)$/m', $contents, $match)) {
            return false;
        }

        return trim($match[1], " \t\r\"'") !== '';
    }
}
