<?php

namespace Tests\Feature;

use Tests\TestCase;

/** php artisan app:ensure-key, composer setup's key step: never replaces an existing APP_KEY. */
class EnsureAppKeyTest extends TestCase
{
    private const EXISTING = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private string $dir;

    private ?string $realEnvHash;

    protected function setUp(): void
    {
        parent::setUp();

        // A scratch .env: the project's own must never be read or written here.
        $this->realEnvHash = is_file(base_path('.env')) ? md5_file(base_path('.env')) : null;
        $this->dir = sys_get_temp_dir().'/ensure-key-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
        $this->assertSame($this->dir.'/.env', $this->app->environmentFilePath());
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);
        $this->assertSame($this->realEnvHash, is_file(base_path('.env')) ? md5_file(base_path('.env')) : null, 'The project .env was changed.');

        parent::tearDown();
    }

    private function writeEnv(string $keyLine): string
    {
        $contents = "APP_NAME=Alodium\n{$keyLine}\nAPP_URL=http://localhost:8000\n";
        file_put_contents($this->dir.'/.env', $contents);

        return $contents;
    }

    public function test_a_fresh_install_gets_a_key_exactly_as_key_generate_gives_it(): void
    {
        $this->writeEnv('APP_KEY=');
        // As on a fresh install: the config holds the empty key from .env.
        config(['app.key' => '']);

        $this->artisan('app:ensure-key')
            ->expectsOutputToContain('Application key set successfully.')
            ->doesntExpectOutputToContain('skipped')
            ->assertSuccessful();

        $env = file_get_contents($this->dir.'/.env');
        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $env);
        preg_match('/^APP_KEY=base64:(.+)$/m', $env, $match);
        $this->assertSame(32, strlen(base64_decode($match[1])), 'A 256-bit key, as for AES-256-CBC.');
        // Nothing else in the file changes.
        $this->assertSame("APP_NAME=Alodium\nAPP_KEY=base64:{$match[1]}\nAPP_URL=http://localhost:8000\n", $env);
    }

    public function test_an_existing_key_is_kept_and_the_skip_is_explained(): void
    {
        $before = $this->writeEnv('APP_KEY='.self::EXISTING);
        config(['app.key' => self::EXISTING]);

        $this->artisan('app:ensure-key')
            ->expectsOutputToContain('APP_KEY is already set in .env, so key generation was skipped.')
            ->expectsOutputToContain('log every user out')
            ->expectsOutputToContain('php artisan key:generate')
            ->doesntExpectOutputToContain('Application key set successfully.')
            ->assertSuccessful();

        $this->assertSame($before, file_get_contents($this->dir.'/.env'));
        $this->assertSame(self::EXISTING, config('app.key'));
    }

    public function test_a_quoted_key_counts_as_set_and_quotes_alone_do_not(): void
    {
        $before = $this->writeEnv('APP_KEY="'.self::EXISTING.'"');
        $this->artisan('app:ensure-key')->expectsOutputToContain('skipped')->assertSuccessful();
        $this->assertSame($before, file_get_contents($this->dir.'/.env'));

        // A commented-out key isn't a key either.
        $this->writeEnv("# APP_KEY=old\nAPP_KEY=");
        config(['app.key' => '']);
        $this->artisan('app:ensure-key')->expectsOutputToContain('Application key set successfully.')->assertSuccessful();
        $this->assertStringContainsString("# APP_KEY=old\nAPP_KEY=base64:", file_get_contents($this->dir.'/.env'));
    }

    public function test_composer_setup_uses_the_guard_not_key_generate(): void
    {
        $setup = json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['setup'];

        $this->assertContains('@php artisan app:ensure-key', $setup);
        $this->assertEmpty(preg_grep('/key:generate/', $setup));
    }
}
