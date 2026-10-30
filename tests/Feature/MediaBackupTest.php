<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MediaBackupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'filesystems.disks.backups.key' => 'key',
            'filesystems.disks.backups.secret' => 'secret',
            'filesystems.disks.backups.bucket' => 'alodium-backups',
        ]);
        Storage::fake('public');
        Storage::fake('backups');

        Storage::disk('public')->put('.gitignore', "*\n!.gitignore\n");
        Storage::disk('public')->put('1/photo.jpg', 'jpeg-bytes');
        Storage::disk('public')->put('1/conversions/photo-thumb.webp', 'webp');
        Storage::disk('public')->put('2/logo.svg', '<svg/>');
    }

    private function remote(): array
    {
        $files = Storage::disk('backups')->allFiles();
        sort($files);

        return $files;
    }

    public function test_nothing_happens_without_s3_config(): void
    {
        config(['filesystems.disks.backups.bucket' => null]);

        $this->artisan('media:backup-remote')->expectsOutputToContain('Media backup skipped')->assertSuccessful();

        $this->assertSame([], $this->remote());
    }

    public function test_new_files_are_uploaded_with_their_relative_paths(): void
    {
        $this->artisan('media:backup-remote')
            ->expectsOutputToContain('3 uploaded, 0 unchanged, 0 failed')
            ->assertSuccessful();

        $this->assertSame([
            'media/1/conversions/photo-thumb.webp',
            'media/1/photo.jpg',
            'media/2/logo.svg',
        ], $this->remote()); // .gitignore is not copied
        $this->assertSame('jpeg-bytes', Storage::disk('backups')->get('media/1/photo.jpg'));
    }

    public function test_unchanged_files_are_skipped(): void
    {
        $this->artisan('media:backup-remote')->assertSuccessful();
        Log::spy();

        $this->artisan('media:backup-remote')
            ->expectsOutputToContain('0 uploaded, 3 unchanged, 0 failed')
            ->assertSuccessful();

        Log::shouldHaveReceived('info')->withArgs(fn ($m, $c = []) => $m === 'Media backup finished.'
            && $c === ['uploaded' => 0, 'skipped' => 3, 'failed' => 0]);
    }

    public function test_files_with_a_different_size_are_uploaded_again(): void
    {
        $this->artisan('media:backup-remote')->assertSuccessful();
        Storage::disk('public')->put('1/photo.jpg', 'a-different-and-longer-jpeg');

        $this->artisan('media:backup-remote')
            ->expectsOutputToContain('1 uploaded, 2 unchanged, 0 failed')
            ->assertSuccessful();

        $this->assertSame('a-different-and-longer-jpeg', Storage::disk('backups')->get('media/1/photo.jpg'));
    }

    public function test_remote_files_are_never_deleted(): void
    {
        Storage::disk('backups')->put('media/9/deleted-long-ago.jpg', 'old');
        $this->artisan('media:backup-remote')->assertSuccessful();

        Storage::disk('public')->deleteDirectory('2'); // media deleted locally
        $this->artisan('media:backup-remote')->assertSuccessful();

        $this->assertContains('media/2/logo.svg', $this->remote());
        $this->assertContains('media/9/deleted-long-ago.jpg', $this->remote());
        $this->assertCount(4, $this->remote());
    }

    public function test_a_failed_upload_does_not_stop_the_others_and_fails_the_command(): void
    {
        $fake = Storage::disk('backups');
        $failing = Mockery::mock($fake)->makePartial();
        $failing->shouldReceive('writeStream')->andReturnUsing(function ($path, $stream) use ($fake) {
            if ($path === 'media/1/photo.jpg') {
                throw new RuntimeException('Connection reset');
            }

            return $fake->writeStream($path, $stream);
        });
        Storage::set('backups', $failing);
        Log::spy();

        $this->artisan('media:backup-remote')
            ->expectsOutputToContain('Upload failed: 1/photo.jpg: Connection reset')
            ->expectsOutputToContain('2 uploaded, 0 unchanged, 1 failed')
            ->assertFailed();

        $this->assertSame(['media/1/conversions/photo-thumb.webp', 'media/2/logo.svg'], $this->remote());
        Log::shouldHaveReceived('error')->withArgs(fn ($m, $c = []) => $m === 'Media backup: upload failed.' && $c['file'] === '1/photo.jpg');
    }

    public function test_media_backup_is_scheduled_daily_at_half_past_two(): void
    {
        $this->assertSame('30 2 * * *', $this->scheduleFor('media:backup-remote'));
    }

    /** Cron expression the scheduler uses for a command (independent of schedule:list layout). */
    private function scheduleFor(string $command): ?string
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, $command));

        return $event?->expression;
    }
}
