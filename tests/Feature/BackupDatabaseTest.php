<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        // Never touch real backups in storage/app/backups.
        $this->directory = sys_get_temp_dir().'/alodium-backup-test-'.uniqid();
        config([
            'database.backups.path' => $this->directory,
            'database.backups.keep' => 30,
            // The command reads credentials from config; mysqldump itself is faked.
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'alodium',
            'database.connections.mysql.username' => 'alodium',
            'database.connections.mysql.password' => 'p@ss"word\\',
        ]);
        $this->travelTo(Carbon::parse('2026-10-19 02:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function fakeDump(string $sql = "CREATE TABLE posts (id int);\n-- Dump completed on 2026-10-19 02:00:01\n", int $exitCode = 0, string $errors = ''): void
    {
        // Like mysqldump: writes the dump to the --result-file path.
        Process::fake(['*' => function (PendingProcess $process) use ($sql, $errors, $exitCode) {
            $option = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--result-file='));
            if ($sql !== '') {
                file_put_contents(substr($option, strlen('--result-file=')), $sql);
            }

            return Process::result('', $errors, $exitCode);
        }]);
    }

    private function backups(): array
    {
        return array_map('basename', glob($this->directory.'/*') ?: []);
    }

    public function test_creates_a_compressed_non_empty_backup_with_the_expected_name(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        $this->assertSame(['alodium-backup-2026-10-19-0200.sql.gz'], $this->backups());
        $file = $this->directory.'/alodium-backup-2026-10-19-0200.sql.gz';
        $this->assertGreaterThan(0, filesize($file));
        $this->assertStringContainsString('CREATE TABLE posts', gzdecode(file_get_contents($file)));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }

    public function test_filename_format(): void
    {
        $this->fakeDump();
        $this->travelTo(Carbon::parse('2026-01-05 23:07:00', 'UTC'));

        $this->artisan('db:backup')->assertSuccessful();

        $this->assertMatchesRegularExpression('/^alodium-backup-\d{4}-\d{2}-\d{2}-\d{4}\.sql\.gz$/', $this->backups()[0]);
        $this->assertSame('alodium-backup-2026-01-05-2307.sql.gz', $this->backups()[0]);
    }

    public function test_a_second_backup_in_the_same_minute_does_not_overwrite_the_first(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();
        $this->travel(5)->seconds();
        $this->artisan('db:backup')->assertSuccessful();

        $this->assertCount(2, $this->backups());
    }

    public function test_password_is_not_passed_on_the_command_line(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) {
            $args = implode(' ', $process->command);

            return str_starts_with($process->command[1], '--defaults-extra-file=')
                && ! str_contains($args, 'p@ss')
                && in_array('--single-transaction', $process->command, true)
                && in_array('--no-tablespaces', $process->command, true)
                && end($process->command) === 'alodium'
                && collect($process->command)->contains(fn ($arg) => str_starts_with($arg, '--result-file='));
        });
    }

    public function test_keeps_only_the_newest_30_backups(): void
    {
        File::ensureDirectoryExists($this->directory);
        foreach (range(1, 30) as $day) {
            touch(sprintf('%s/alodium-backup-2026-09-%02d-0200.sql.gz', $this->directory, $day));
        }
        // 30 September files plus 2 August ones (older), and an unrelated file.
        touch($this->directory.'/alodium-backup-2026-08-30-0200.sql.gz');
        touch($this->directory.'/alodium-backup-2026-08-31-0200.sql.gz');
        touch($this->directory.'/notes.txt');
        $this->fakeDump();

        $this->artisan('db:backup')->expectsOutputToContain('Pruned 3 old backup(s).')->assertSuccessful();

        $remaining = array_values(array_filter($this->backups(), fn ($f) => str_starts_with($f, 'alodium-backup-')));
        $this->assertCount(30, $remaining);
        $this->assertContains('alodium-backup-2026-10-19-0200.sql.gz', $remaining); // the new one
        $this->assertNotContains('alodium-backup-2026-09-01-0200.sql.gz', $remaining); // oldest September one
        $this->assertNotContains('alodium-backup-2026-08-30-0200.sql.gz', $remaining);
        $this->assertContains('notes.txt', $this->backups()); // other files are left alone
    }

    public function test_failed_dump_logs_an_error_leaves_no_file_and_prunes_nothing(): void
    {
        File::ensureDirectoryExists($this->directory);
        foreach (range(1, 31) as $day) {
            touch(sprintf('%s/alodium-backup-2026-08-%02d-0200.sql.gz', $this->directory, $day));
        }
        $this->fakeDump('', 2, "mysqldump: Got error: 1045: Access denied for user 'alodium'");
        Log::spy();

        $this->artisan('db:backup')->assertFailed();

        $this->assertCount(31, $this->backups()); // nothing new, nothing pruned, no .partial file
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'Access denied'));
    }

    public function test_dump_without_the_completion_marker_is_treated_as_failed(): void
    {
        // e.g. the connection dropped mid-dump: exit code 0 is not proof of a full dump.
        $this->fakeDump("CREATE TABLE posts (id int);\nINSERT INTO posts VALUES (1);\n");

        $this->artisan('db:backup')->expectsOutputToContain('incomplete')->assertFailed();

        $this->assertSame([], $this->backups());
    }

    public function test_refuses_non_mysql_connections(): void
    {
        config(['database.default' => 'sqlite']);
        Process::fake();

        $this->artisan('db:backup')->assertFailed();

        Process::assertNothingRan();
    }

    public function test_creates_the_backup_directory_if_missing(): void
    {
        $this->assertDirectoryDoesNotExist($this->directory);
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        $this->assertDirectoryExists($this->directory);
    }

    public function test_backup_is_scheduled_daily_at_two(): void
    {
        $this->assertSame('0 2 * * *', $this->scheduleFor('db:backup'));
    }

    /** Cron expression the scheduler uses for a command (independent of schedule:list layout). */
    private function scheduleFor(string $command): ?string
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, $command));

        return $event?->expression;
    }
}
