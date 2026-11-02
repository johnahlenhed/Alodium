<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\ConfigCacheCommand;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/** php artisan app:configure-env: the .env secrets and credentials in one pass. */
class ConfigureEnvTest extends TestCase
{
    private const S3_QUESTION = 'Set up off-server backups to an S3-compatible bucket now? (README step 10; you can run this command again later)';

    private const RESEND_QUESTION = 'Resend API key (starts with re_; leave empty to skip)';

    private const SENTRY_QUESTION = 'Sentry DSN (https://<key>@<org>.ingest.sentry.io/<project>; leave empty to skip)';

    private const PASSWORD_QUESTION = 'Database password (leave empty to generate one)';

    private string $dir;

    private ?string $realEnvHash;

    private bool $realConfigCached;

    private Command $configCache;

    protected function setUp(): void
    {
        parent::setUp();

        // A scratch .env and config cache: the project's own must never be touched here.
        $this->realEnvHash = is_file(base_path('.env')) ? md5_file(base_path('.env')) : null;
        $this->realConfigCached = is_file(base_path('bootstrap/cache/config.php'));
        $this->dir = sys_get_temp_dir().'/configure-env-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app->useEnvironmentPath($this->dir);
        $this->assertSame($this->dir.'/.env', $this->app->environmentFilePath());
        $_SERVER['APP_CONFIG_CACHE'] = $this->dir.'/config.php';
        $this->assertSame($this->dir.'/config.php', $this->app->getCachedConfigPath());

        // The real config:cache builds a fresh application from the project's own
        // .env, and that points the facades and the container at it: a later
        // Artisan::call() would then run against the project's .env. So it is
        // replaced by a stub that counts its runs; one test runs the real one.
        $this->configCache = new class extends Command
        {
            protected $signature = 'config:cache';

            public int $runs = 0;

            public function handle(): int
            {
                $this->runs++;
                $this->components->info('Configuration cached successfully.');

                return self::SUCCESS;
            }
        };
        $this->app->make(Kernel::class)->registerCommand($this->configCache);
    }

    protected function tearDown(): void
    {
        // Back to this test's application, whatever config:cache did.
        Container::setInstance($this->app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);

        unset($_SERVER['APP_CONFIG_CACHE']);
        @unlink($this->dir.'/config.php');
        @unlink($this->dir.'/.env');
        @rmdir($this->dir);
        $this->assertSame($this->realEnvHash, is_file(base_path('.env')) ? md5_file(base_path('.env')) : null, 'The project .env was changed.');
        $this->assertSame($this->realConfigCached, is_file(base_path('bootstrap/cache/config.php')), 'The project config cache was changed.');

        parent::tearDown();
    }

    /** .env.example as `cp .env.example .env` gives it, with some lines changed. */
    private function writeEnv(array $set = []): void
    {
        $contents = file_get_contents(base_path('.env.example'));
        foreach ($set as $name => $line) {
            $contents = preg_replace('/^'.$name.'=.*$/m', $line, $contents);
        }
        file_put_contents($this->dir.'/.env', $contents);
    }

    private function env(): string
    {
        return file_get_contents($this->dir.'/.env');
    }

    private function parsed(): array
    {
        return Dotenv::parse($this->env());
    }

    /** Runs it without a terminal, through this test's own console kernel. */
    private function runQuietly(): string
    {
        $this->assertSame($this->dir.'/.env', $this->app->environmentFilePath());
        $kernel = $this->app->make(Kernel::class);
        $this->assertSame(0, $kernel->call('app:configure-env', ['--no-interaction' => true]));

        return $kernel->output();
    }

    public function test_the_secrets_are_generated_into_env_and_the_config_cached_once(): void
    {
        $this->writeEnv(['DB_USERNAME' => 'DB_USERNAME=alodium', 'DB_PASSWORD' => 'DB_PASSWORD=already-chosen']);

        $output = $this->runQuietly();
        $env = $this->parsed();

        // As "openssl rand -hex 32" and "openssl rand -base64 32" in the README.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $env['WEBHOOK_SECRET']);
        $this->assertSame(32, strlen(base64_decode($env['BACKUP_ENCRYPTION_PASSPHRASE'], true)));
        // Quoted like the README's example: base64 has + / =.
        $this->assertStringContainsString('BACKUP_ENCRYPTION_PASSPHRASE="'.$env['BACKUP_ENCRYPTION_PASSPHRASE'].'"', $this->env());

        // The secrets are written, never shown.
        $this->assertStringNotContainsString($env['WEBHOOK_SECRET'], $output);
        $this->assertStringNotContainsString($env['BACKUP_ENCRYPTION_PASSPHRASE'], $output);
        $this->assertStringContainsString('Copy BACKUP_ENCRYPTION_PASSPHRASE from .env into a password manager now', $output);

        $this->assertSame(1, $this->configCache->runs, 'config:cache runs exactly once, at the end.');
        $this->assertSame(1, substr_count($output, 'Configuration cached successfully'));

        // Nothing without a person to ask: noted, not filled with anything.
        $this->assertSame('', $env['RESEND_API_KEY']);
        $this->assertSame('', $env['BACKUP_S3_KEY']);
        $this->assertStringContainsString('not set: database backups and media stay on this server only', $output);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->writeEnv(['DB_USERNAME' => 'DB_USERNAME=alodium', 'DB_PASSWORD' => 'DB_PASSWORD=already-chosen']);
        $this->runQuietly();
        $after = $this->env();

        $output = $this->runQuietly();

        $this->assertSame($after, $this->env());
        $this->assertStringContainsString('Nothing to write', $output);
        $this->assertStringNotContainsString('password manager', $output, 'No new passphrase, so no reminder.');
        $this->assertSame(2, $this->configCache->runs, 'Once per run.');
    }

    /** The real config:cache, once: the values written take effect, cached where APP_CONFIG_CACHE says. */
    public function test_the_real_config_cache_runs_after_writing(): void
    {
        $this->app->make(Kernel::class)->registerCommand($this->app->make(ConfigCacheCommand::class));
        $this->writeEnv(['DB_USERNAME' => 'DB_USERNAME=alodium', 'DB_PASSWORD' => 'DB_PASSWORD=already-chosen']);

        $this->artisan('app:configure-env', ['--no-interaction' => true])
            ->expectsOutputToContain('Configuration cached successfully')
            ->assertSuccessful();

        $this->assertFileExists($this->dir.'/config.php');
        $this->assertIsArray(require $this->dir.'/config.php');
    }

    /** Most of all the passphrase: a new one couldn't decrypt the existing off-server backups. */
    public function test_values_that_are_set_are_never_replaced_or_asked_for(): void
    {
        $this->writeEnv([
            'WEBHOOK_SECRET' => 'WEBHOOK_SECRET=old-webhook-secret',
            'BACKUP_ENCRYPTION_PASSPHRASE' => 'BACKUP_ENCRYPTION_PASSPHRASE="old+pass/phrase="',
            'DB_USERNAME' => 'DB_USERNAME=client',
            'DB_PASSWORD' => 'DB_PASSWORD=old-db-password',
            'RESEND_API_KEY' => 'RESEND_API_KEY=re_old_key',
            'SENTRY_LARAVEL_DSN' => 'SENTRY_LARAVEL_DSN=https://abc@o1.ingest.sentry.io/2',
            'BACKUP_S3_KEY' => 'BACKUP_S3_KEY=old-key',
            'BACKUP_S3_SECRET' => 'BACKUP_S3_SECRET=old-secret',
            'BACKUP_S3_BUCKET' => 'BACKUP_S3_BUCKET=old-bucket',
        ]);
        $before = $this->env();

        // Interactive, but with no questions expected: asking one fails the test.
        $this->artisan('app:configure-env')
            ->expectsOutputToContain('Nothing to write')
            ->assertSuccessful();

        $this->assertSame($before, $this->env());
    }

    public function test_the_values_only_a_person_has_are_asked_for_and_written(): void
    {
        $this->writeEnv();

        $this->artisan('app:configure-env')
            ->expectsQuestion('Database user', 'client_db')
            ->expectsQuestion(self::PASSWORD_QUESTION, '')
            ->expectsQuestion(self::RESEND_QUESTION, 'not-a-key')
            ->expectsOutputToContain('That doesn\'t look like a Resend API key')
            ->expectsQuestion(self::RESEND_QUESTION, 're_Abc123_def')
            ->expectsQuestion(self::SENTRY_QUESTION, 'https://pub@o99.ingest.sentry.io/123')
            ->expectsConfirmation(self::S3_QUESTION, 'yes')
            ->expectsQuestion('Access key ID', 'AKIDEXAMPLE')
            ->expectsQuestion('Secret access key', 'very/secret+key')
            ->expectsQuestion('Bucket name', 'alodium-backups')
            ->expectsQuestion('Endpoint, e.g. https://<account-id>.r2.cloudflarestorage.com (copy it from the bucket settings; leave empty for AWS S3)', 'https://acc.r2.cloudflarestorage.com')
            ->expectsQuestion('Region (auto for Cloudflare R2)', 'auto')
            ->expectsOutputToContain("CREATE USER 'client_db'@'localhost' IDENTIFIED BY '")
            ->doesntExpectOutputToContain('re_Abc123_def')
            ->doesntExpectOutputToContain('very/secret+key')
            ->assertSuccessful();

        $env = $this->parsed();
        $this->assertSame('alodium', $env['DB_DATABASE'], 'Kept: .env.example already names it.');
        $this->assertSame('client_db', $env['DB_USERNAME']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $env['DB_PASSWORD'], 'Left empty, so generated.');
        $this->assertSame('re_Abc123_def', $env['RESEND_API_KEY']);
        $this->assertSame('https://pub@o99.ingest.sentry.io/123', $env['SENTRY_LARAVEL_DSN']);
        $this->assertSame(['AKIDEXAMPLE', 'very/secret+key', 'alodium-backups', 'https://acc.r2.cloudflarestorage.com', 'auto'],
            [$env['BACKUP_S3_KEY'], $env['BACKUP_S3_SECRET'], $env['BACKUP_S3_BUCKET'], $env['BACKUP_S3_ENDPOINT'], $env['BACKUP_S3_REGION']]);
        $this->assertSame(1, $this->configCache->runs);
    }

    public function test_the_generated_database_password_is_in_the_printed_sql(): void
    {
        $this->writeEnv(['DB_USERNAME' => 'DB_USERNAME=alodium']);

        $output = $this->runQuietly();
        $password = $this->parsed()['DB_PASSWORD'];

        $this->assertStringContainsString("CREATE USER 'alodium'@'localhost' IDENTIFIED BY '{$password}';", $output);
        $this->assertStringContainsString("GRANT ALL PRIVILEGES ON alodium.* TO 'alodium'@'localhost';", $output);
    }

    public function test_off_server_backups_can_be_skipped_with_a_note(): void
    {
        $this->writeEnv(['DB_USERNAME' => 'DB_USERNAME=alodium', 'DB_PASSWORD' => 'DB_PASSWORD=already-chosen']);

        $this->artisan('app:configure-env')
            ->expectsQuestion(self::RESEND_QUESTION, '')
            ->expectsQuestion(self::SENTRY_QUESTION, '')
            ->expectsConfirmation(self::S3_QUESTION, 'no')
            ->expectsOutputToContain('not set: database backups and media stay on this server only. Off-server copies won\'t work until these are configured')
            ->expectsOutputToContain('not set: invitations, password resets and contact form emails can\'t be sent until it is')
            ->expectsOutputToContain('not set: errors are not reported to Sentry')
            ->assertSuccessful();

        $env = $this->parsed();
        foreach (['BACKUP_S3_KEY', 'BACKUP_S3_SECRET', 'BACKUP_S3_BUCKET', 'BACKUP_S3_ENDPOINT', 'RESEND_API_KEY', 'SENTRY_LARAVEL_DSN'] as $name) {
            $this->assertSame('', $env[$name], $name);
        }
        // Skipping S3 doesn't skip the rest: the passphrase is ready for when it is set up.
        $this->assertNotSame('', $env['BACKUP_ENCRYPTION_PASSPHRASE']);
        $this->assertNotSame('', $env['WEBHOOK_SECRET']);
    }

    public function test_a_typed_password_with_awkward_characters_reads_back_exactly(): void
    {
        $this->writeEnv(['DB_USERNAME' => 'DB_USERNAME=alodium']);
        $tricky = 'p@ss word#1 ${HOME} "q"';

        $this->artisan('app:configure-env')
            ->expectsQuestion(self::PASSWORD_QUESTION, 'has \' and $ both')
            ->expectsOutputToContain('which .env cannot hold')
            ->expectsQuestion(self::PASSWORD_QUESTION, $tricky)
            ->expectsQuestion(self::RESEND_QUESTION, '')
            ->expectsQuestion(self::SENTRY_QUESTION, '')
            ->expectsConfirmation(self::S3_QUESTION, 'no')
            ->doesntExpectOutputToContain('CREATE USER')
            ->assertSuccessful();

        // Exactly as typed, as Laravel reads .env: no ${HOME} expansion, no lost # comment.
        $this->assertSame($tricky, $this->parsed()['DB_PASSWORD']);
    }

    public function test_sqlite_needs_no_database_credentials(): void
    {
        $this->writeEnv(['DB_CONNECTION' => 'DB_CONNECTION=sqlite']);

        $output = $this->runQuietly();

        $this->assertStringContainsString('not needed (DB_CONNECTION is sqlite)', $output);
        $this->assertSame('', $this->parsed()['DB_PASSWORD']);
    }

    public function test_a_missing_env_file_is_reported(): void
    {
        $this->artisan('app:configure-env')
            ->expectsOutputToContain('Create it first: cp .env.example .env')
            ->assertFailed();
    }

    public function test_env_example_sets_a_queue_retry_above_the_worker_timeout(): void
    {
        $retryAfter = (int) Dotenv::parse(file_get_contents(base_path('.env.example')))['DB_QUEUE_RETRY_AFTER'];
        preg_match('/queue:work .*--timeout=(\d+)/', file_get_contents(base_path('README.md')), $match);

        $this->assertGreaterThan((int) $match[1], $retryAfter, 'DB_QUEUE_RETRY_AFTER must exceed the worker --timeout in README step 7.');
    }
}
