<?php

namespace App\Console\Commands;

use App\Services\OffsiteBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Throwable;

/**
 * Copies uploaded media (storage/app/public) to the "backups" bucket under media/,
 * so images survive the loss of the server. Scheduled daily at 02:30 (routes/console.php).
 *
 * - Only new files and files whose size changed are uploaded.
 * - Remote files are never deleted, so deleted media can still be recovered.
 * - Files are stored as they are (not encrypted): they are public on the site anyway.
 */
class BackupMediaRemote extends Command
{
    protected $signature = 'media:backup-remote';

    protected $description = 'Copy new and changed files from storage/app/public to the off-server backup bucket';

    private const PREFIX = 'media';

    public function handle(OffsiteBackup $offsite): int
    {
        if (! $offsite->isConfigured()) {
            $this->line('Media backup skipped: BACKUP_S3_* is not configured.');

            return self::SUCCESS;
        }

        $local = Storage::disk('public');
        $remote = $offsite->disk();

        try {
            $remoteSizes = $this->remoteSizes($remote);
        } catch (Throwable $e) {
            Log::error('Media backup failed: could not list the bucket.', ['error' => $e->getMessage()]);
            $this->error('Media backup failed: could not list the bucket: '.$e->getMessage());

            return self::FAILURE;
        }

        $uploaded = $skipped = $failed = 0;

        foreach ($local->allFiles() as $path) {
            if (basename($path) === '.gitignore') {
                continue;
            }

            $target = self::PREFIX.'/'.$path;

            if (($remoteSizes[$target] ?? null) === $local->size($path)) {
                $skipped++;

                continue;
            }

            // One failing file is logged and the rest still run.
            try {
                $stream = $local->readStream($path);
                $remote->writeStream($target, $stream);
                $uploaded++;
            } catch (Throwable $e) {
                $failed++;
                Log::error('Media backup: upload failed.', ['file' => $path, 'error' => $e->getMessage()]);
                $this->error("Upload failed: {$path}: {$e->getMessage()}");
            } finally {
                if (isset($stream) && is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $context = ['uploaded' => $uploaded, 'skipped' => $skipped, 'failed' => $failed];
        $failed > 0
            ? Log::error('Media backup finished with failures.', $context)
            : Log::info('Media backup finished.', $context);
        $this->info("Media backup: {$uploaded} uploaded, {$skipped} unchanged, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Size of every object under media/, from a single listing of the bucket
     * (rather than one request per file).
     *
     * @return array<string, int> remote path => size in bytes
     */
    private function remoteSizes($remote): array
    {
        $sizes = [];

        foreach ($remote->getDriver()->listContents(self::PREFIX, true) as $item) {
            if ($item instanceof FileAttributes) {
                $sizes[$item->path()] = $item->fileSize();
            }
        }

        return $sizes;
    }
}
