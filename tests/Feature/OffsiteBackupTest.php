<?php

namespace Tests\Feature;

use App\Services\OffsiteBackup;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OffsiteBackupTest extends TestCase
{
    private const PASSPHRASE = 'correct horse battery staple';

    private const DUMP = "CREATE TABLE posts (id int);\nINSERT INTO posts VALUES (1);\n-- Dump completed on 2026-10-21 02:00:01\n";

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/alodium-offsite-test-'.uniqid();
        config([
            'database.backups.path' => $this->directory,
            'database.backups.passphrase' => self::PASSPHRASE,
            'database.backups.remote_keep' => 30,
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'alodium',
            'filesystems.disks.backups.key' => 'key',
            'filesystems.disks.backups.secret' => 'secret',
            'filesystems.disks.backups.bucket' => 'alodium-backups',
        ]);
        Storage::fake('backups');
        $this->travelTo(Carbon::parse('2026-10-21 02:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /** Fakes mysqldump only; openssl runs for real. */
    private function fakeDump(): void
    {
        Process::fake([
            "'mysqldump'*" => function (PendingProcess $process) {
                $option = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--result-file='));
                file_put_contents(substr($option, strlen('--result-file=')), self::DUMP);

                return Process::result();
            },
        ]);
    }

    private function remoteFiles(): array
    {
        return Storage::disk('backups')->allFiles();
    }

    private function localFiles(): array
    {
        return array_map('basename', glob($this->directory.'/*') ?: []);
    }

    public function test_nothing_is_uploaded_without_full_bucket_config(): void
    {
        config(['filesystems.disks.backups.bucket' => null]);
        $this->fakeDump();

        $this->artisan('db:backup')->expectsOutputToContain('Off-server copy skipped')->assertSuccessful();

        $this->assertSame([], $this->remoteFiles());
        $this->assertSame(['alodium-backup-2026-10-21-0200.sql.gz'], $this->localFiles());
    }

    public function test_no_upload_option_skips_the_off_server_copy(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup', ['--no-upload' => true])->assertSuccessful();

        $this->assertSame([], $this->remoteFiles());
        $this->assertCount(1, $this->localFiles());
    }

    public function test_nothing_is_uploaded_without_a_passphrase(): void
    {
        config(['database.backups.passphrase' => null]);
        $this->fakeDump();
        Log::spy();

        $this->artisan('db:backup')->assertFailed();

        $this->assertSame([], $this->remoteFiles());
        $this->assertSame(['alodium-backup-2026-10-21-0200.sql.gz'], $this->localFiles()); // local backup still made
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'BACKUP_ENCRYPTION_PASSPHRASE is not set'));
    }

    public function test_encrypted_file_is_uploaded_and_is_not_plain_gzip(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->expectsOutputToContain('Off-server copy uploaded')->assertSuccessful();

        $this->assertSame(['backups/alodium-backup-2026-10-21-0200.sql.gz.enc'], $this->remoteFiles());
        $remote = Storage::disk('backups')->get('backups/alodium-backup-2026-10-21-0200.sql.gz.enc');
        $this->assertStringStartsWith('Salted__', $remote);          // openssl's encrypted format
        $this->assertNotSame("\x1f\x8b", substr($remote, 0, 2));    // not a gzip file
        $this->assertStringNotContainsString('CREATE TABLE', $remote);
        $this->assertFalse(@gzdecode($remote)); // not decompressible as-is

        // The temporary encrypted file is gone; the local backup is untouched.
        $this->assertSame(['alodium-backup-2026-10-21-0200.sql.gz'], $this->localFiles());
    }

    public function test_decrypting_the_uploaded_file_gives_back_the_original_dump(): void
    {
        $this->fakeDump();
        $this->artisan('db:backup')->assertSuccessful();

        $downloaded = $this->directory.'/downloaded.enc';
        $decrypted = $this->directory.'/decrypted.sql.gz';
        file_put_contents($downloaded, Storage::disk('backups')->get('backups/alodium-backup-2026-10-21-0200.sql.gz.enc'));

        app(OffsiteBackup::class)->decrypt($downloaded, $decrypted);

        $this->assertSame(self::DUMP, gzdecode(file_get_contents($decrypted)));
        $this->assertSame(
            file_get_contents($this->directory.'/alodium-backup-2026-10-21-0200.sql.gz'),
            file_get_contents($decrypted),
        );
    }

    public function test_the_readme_decrypt_command_works(): void
    {
        $this->fakeDump();
        $this->artisan('db:backup')->assertSuccessful();
        $encrypted = $this->directory.'/remote.enc';
        file_put_contents($encrypted, Storage::disk('backups')->get('backups/alodium-backup-2026-10-21-0200.sql.gz.enc'));
        file_put_contents($this->directory.'/pass.txt', self::PASSPHRASE);

        // Exactly the documented command (with -pass instead of the interactive prompt).
        $result = Process::path($this->directory)->run(
            'openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in remote.enc -out restored.sql.gz -pass file:pass.txt'
        );

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame(self::DUMP, gzdecode(file_get_contents($this->directory.'/restored.sql.gz')));
    }

    public function test_a_failed_upload_leaves_the_local_backup_and_remote_backups_alone(): void
    {
        $this->fakeDump();
        foreach (range(1, 31) as $day) {
            Storage::disk('backups')->put(sprintf('backups/alodium-backup-2026-08-%02d-0200.sql.gz.enc', $day), 'Salted__old');
        }
        $this->app->instance(OffsiteBackup::class, new class extends OffsiteBackup
        {
            public function upload(string $encryptedFile, string $fileName): void
            {
                throw new RuntimeException('Could not connect to the storage endpoint.');
            }
        });
        Log::spy();

        $this->artisan('db:backup')
            ->expectsOutputToContain('Could not connect to the storage endpoint')
            ->assertFailed();

        $this->assertCount(31, $this->remoteFiles()); // nothing pruned
        $this->assertSame(['alodium-backup-2026-10-21-0200.sql.gz'], $this->localFiles()); // intact, no .enc left
        $this->assertSame(self::DUMP, gzdecode(file_get_contents($this->directory.'/alodium-backup-2026-10-21-0200.sql.gz')));
        Log::shouldHaveReceived('error')->withArgs(fn ($m) => str_contains($m, 'Off-server backup failed'));
    }

    public function test_remote_pruning_keeps_only_the_newest_30(): void
    {
        $this->fakeDump();
        foreach (range(1, 31) as $day) {
            Storage::disk('backups')->put(sprintf('backups/alodium-backup-2026-08-%02d-0200.sql.gz.enc', $day), 'Salted__old');
        }
        Storage::disk('backups')->put('backups/README.txt', 'not a backup');

        $this->artisan('db:backup')->expectsOutputToContain('Pruned 2 old off-server backup(s).')->assertSuccessful();

        $backups = array_values(array_filter($this->remoteFiles(), fn ($f) => str_contains($f, 'alodium-backup-')));
        $this->assertCount(30, $backups);
        $this->assertContains('backups/alodium-backup-2026-10-21-0200.sql.gz.enc', $backups);
        $this->assertNotContains('backups/alodium-backup-2026-08-01-0200.sql.gz.enc', $backups);
        $this->assertNotContains('backups/alodium-backup-2026-08-02-0200.sql.gz.enc', $backups);
        $this->assertContains('backups/README.txt', $this->remoteFiles()); // other files left alone
    }

    public function test_a_verified_upload_records_its_time(): void
    {
        $this->fakeDump();

        $this->artisan('db:backup')->assertSuccessful();

        $record = app(OffsiteBackup::class)->lastUpload();
        $this->assertSame('2026-10-21T02:00:00+00:00', $record['at']);
        $this->assertSame('backups/alodium-backup-2026-10-21-0200.sql.gz.enc', $record['file']);
        // Stored next to the backups, owner-only, and not mistaken for a backup by pruning.
        $file = $this->directory.'/.offsite-last-upload.json';
        $this->assertFileExists($file);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertSame(['alodium-backup-2026-10-21-0200.sql.gz'], $this->localFiles()); // glob skips dotfiles
    }

    public function test_a_failed_upload_keeps_the_previous_upload_time(): void
    {
        $this->fakeDump();
        $this->artisan('db:backup')->assertSuccessful();
        $before = app(OffsiteBackup::class)->lastUpload();

        $this->travel(1)->day();
        $this->app->instance(OffsiteBackup::class, new class extends OffsiteBackup
        {
            public function upload(string $encryptedFile, string $fileName): void
            {
                throw new RuntimeException('Network down');
            }
        });
        $this->artisan('db:backup')->assertFailed();

        $this->assertSame($before, app(OffsiteBackup::class)->lastUpload());
        $this->assertSame('2026-10-21T02:00:00+00:00', app(OffsiteBackup::class)->lastUpload()['at']);
    }

    public function test_no_upload_time_is_recorded_without_a_passphrase_or_with_no_upload(): void
    {
        $this->fakeDump();

        config(['database.backups.passphrase' => null]);
        $this->artisan('db:backup')->assertFailed();
        $this->assertNull(app(OffsiteBackup::class)->lastUpload());

        config(['database.backups.passphrase' => self::PASSPHRASE]);
        $this->travel(1)->minute();
        $this->artisan('db:backup', ['--no-upload' => true])->assertSuccessful();
        $this->assertNull(app(OffsiteBackup::class)->lastUpload());
    }

    public function test_the_passphrase_never_appears_in_process_arguments(): void
    {
        $passwordFiles = [];
        Process::fake([
            "'mysqldump'*" => function (PendingProcess $process) {
                $option = collect($process->command)->first(fn ($arg) => str_starts_with($arg, '--result-file='));
                file_put_contents(substr($option, strlen('--result-file=')), self::DUMP);

                return Process::result();
            },
            "'openssl'*" => function (PendingProcess $process) use (&$passwordFiles) {
                $args = $process->command;
                $passwordFile = substr($args[array_search('-pass', $args) + 1], strlen('file:'));
                // The passphrase is in an owner-only file while openssl runs...
                $passwordFiles[] = [$passwordFile, file_get_contents($passwordFile), substr(sprintf('%o', fileperms($passwordFile)), -4)];
                $in = $args[array_search('-in', $args) + 1];
                file_put_contents($args[array_search('-out', $args) + 1], 'Salted__'.file_get_contents($in));

                return Process::result();
            },
        ]);

        $this->artisan('db:backup')->assertSuccessful();

        Process::assertRanTimes(fn (PendingProcess $p) => $p->command[0] === 'openssl', 1);
        Process::assertDidntRun(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), self::PASSPHRASE));
        Process::assertDidntRun(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'correct horse'));

        [$file, $contents, $mode] = $passwordFiles[0];
        $this->assertSame(self::PASSPHRASE, $contents);
        $this->assertSame('0600', $mode);
        $this->assertFileDoesNotExist($file); // ...and deleted afterwards
    }

    public function test_only_encrypted_files_can_be_uploaded(): void
    {
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/plain.sql.gz', gzencode(self::DUMP));

        $this->expectExceptionMessage('Refusing to upload a file that is not encrypted.');

        app(OffsiteBackup::class)->upload($this->directory.'/plain.sql.gz', 'plain.sql.gz.enc');
    }

    public function test_remote_test_command_round_trips_and_cleans_up(): void
    {
        $this->artisan('db:backup-test-remote')
            ->expectsOutputToContain('Off-server backups work')
            ->assertSuccessful();

        $this->assertSame([], $this->remoteFiles());
    }

    public function test_remote_test_command_reports_missing_config(): void
    {
        config(['filesystems.disks.backups.key' => null]);
        $this->artisan('db:backup-test-remote')->expectsOutputToContain('Not configured')->assertFailed();

        config(['filesystems.disks.backups.key' => 'key', 'database.backups.passphrase' => '']);
        $this->artisan('db:backup-test-remote')->expectsOutputToContain('BACKUP_ENCRYPTION_PASSPHRASE is not set')->assertFailed();
    }

    /**
     * A remote key that ends in a newline is not a backup. ($ would match it, and
     * it would sort as the newest, so the real backup would be pruned instead.)
     * Flysystem can't write such a key, but another tool could create one in the bucket.
     */
    public function test_pruning_ignores_a_key_with_a_trailing_newline(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('files')->andReturn([
            'backups/alodium-backup-2026-10-20-0200.sql.gz.enc',
            "backups/alodium-backup-2026-10-21-0200.sql.gz.enc\n",
        ]);
        $disk->shouldNotReceive('delete');

        $offsite = new class($disk) extends OffsiteBackup
        {
            public function __construct(private Filesystem $fake) {}

            public function disk(): Filesystem
            {
                return $this->fake;
            }
        };

        $this->assertSame(0, $offsite->prune(1));
    }
}
