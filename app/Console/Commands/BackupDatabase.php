<?php

namespace App\Console\Commands;

use App\Services\OffsiteBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Number;
use Throwable;

/**
 * Dumps the MySQL database to storage/app/backups/alodium-backup-YYYY-MM-DD-HHMM.sql.gz
 * and keeps the newest 30 backups. Scheduled daily at 02:00 (routes/console.php);
 * can also be run by hand: php artisan db:backup
 *
 * When BACKUP_S3_* and BACKUP_ENCRYPTION_PASSPHRASE are set, an encrypted copy is
 * also uploaded to object storage (App\Services\OffsiteBackup), so backups survive
 * the loss of the server.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup
        {--keep= : Number of local backups to keep (default: config database.backups.keep)}
        {--no-upload : Only make the local backup, skip the off-server copy}';

    protected $description = 'Back up the database to storage/app/backups and prune old backups';

    /** mysqldump's last line. Its absence means the dump stopped part-way. */
    private const COMPLETED_MARKER = '-- Dump completed';

    public function handle(OffsiteBackup $offsite): int
    {
        $connection = config('database.connections.'.config('database.default'));

        if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            return $this->failed('Backups need a MySQL or MariaDB connection, not "'.($connection['driver'] ?? 'unknown').'".');
        }

        $directory = config('database.backups.path');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            return $this->failed("Could not create the backup directory {$directory}.");
        }

        $path = $this->newBackupPath($directory);
        // Written under a temporary name and renamed at the end, so a failed or
        // half-written dump never looks like a backup (and is never pruned as one).
        $partial = $path.'.partial';
        $credentials = $this->writeCredentialsFile($connection);

        try {
            $error = $this->dump($connection['database'], $credentials, $partial);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            @unlink($credentials);
        }

        if ($error !== null) {
            @unlink($partial);

            return $this->failed('Database backup failed: '.$error);
        }

        rename($partial, $path);
        $size = Number::fileSize(filesize($path), precision: 1);
        Log::info('Database backup created.', ['file' => basename($path), 'size' => $size]);
        $this->info("Backup created: {$path} ({$size})");

        $this->prune($directory, (int) ($this->option('keep') ?? config('database.backups.keep')));

        if ($this->option('no-upload')) {
            $this->line('Off-server copy skipped (--no-upload).');

            return self::SUCCESS;
        }

        return $this->copyOffServer($path, $offsite);
    }

    /**
     * Encrypts and uploads the new backup, then prunes old remote copies. Nothing
     * here touches the local backup: if anything fails, it stays as it is and the
     * remote backups are left alone. A failure still exits with a failure code, so
     * the scheduler and the logs show it.
     */
    private function copyOffServer(string $path, OffsiteBackup $offsite): int
    {
        if (! $offsite->isConfigured()) {
            $this->line('Off-server copy skipped: BACKUP_S3_* is not configured.');

            return self::SUCCESS;
        }

        if (! $offsite->hasPassphrase()) {
            return $this->failed('Off-server backup NOT uploaded: BACKUP_ENCRYPTION_PASSPHRASE is not set, and backups are never uploaded unencrypted. The local backup '.basename($path).' was created.');
        }

        $encrypted = $path.'.enc';

        try {
            $offsite->encrypt($path, $encrypted);
            $offsite->upload($encrypted, basename($encrypted));
            $size = Number::fileSize(filesize($encrypted), precision: 1);
        } catch (Throwable $e) {
            return $this->failed('Off-server backup failed: '.$e->getMessage().' The local backup '.basename($path).' was created and is unchanged.');
        } finally {
            @unlink($encrypted);
        }

        Log::info('Off-server backup uploaded.', ['file' => $offsite->remotePath(basename($encrypted)), 'size' => $size]);
        $this->info('Off-server copy uploaded: '.$offsite->remotePath(basename($encrypted))." ({$size})");

        try {
            $pruned = $offsite->prune((int) config('database.backups.remote_keep'));
        } catch (Throwable $e) {
            return $this->failed('Off-server backup uploaded, but pruning old remote backups failed: '.$e->getMessage());
        }

        if ($pruned > 0) {
            Log::info('Old off-server backups pruned.', ['deleted' => $pruned]);
            $this->info("Pruned {$pruned} old off-server backup(s).");
        }

        return self::SUCCESS;
    }

    /**
     * mysqldump writes an uncompressed dump to a temporary file, which is then
     * checked and compressed in PHP. Deliberately not "mysqldump | gzip": in a
     * shell pipe the exit code is gzip's, so a failed dump would still leave a
     * valid, non-empty .gz file and look like a success.
     *
     * @return string|null Error message, or null on success.
     */
    private function dump(string $database, string $credentials, string $partial): ?string
    {
        $raw = $partial.'.sql';

        try {
            $result = Process::timeout(1800)->run([
                'mysqldump',
                // Must be the first option. Keeps the password out of the process list.
                "--defaults-extra-file={$credentials}",
                // Consistent snapshot of InnoDB tables without locking the site.
                '--single-transaction',
                // Stream rows instead of buffering whole tables in memory.
                '--quick',
                // Needed since MySQL 8.0.21 for users without the global PROCESS privilege.
                '--no-tablespaces',
                '--default-character-set=utf8mb4',
                "--result-file={$raw}",
                $database,
            ]);

            if (! $result->successful()) {
                return trim($result->errorOutput()) ?: "mysqldump exited with code {$result->exitCode()}.";
            }
            if (! is_file($raw) || filesize($raw) === 0) {
                return 'mysqldump produced no output.';
            }
            if (! str_contains($this->tail($raw), self::COMPLETED_MARKER)) {
                return 'mysqldump finished but the dump is incomplete (no "Dump completed" line).';
            }

            $this->compress($raw, $partial);
        } finally {
            @unlink($raw);
        }

        return filesize($partial) > 0 ? null : 'The backup file is empty.';
    }

    /** Last bytes of a file, without reading it all into memory. */
    private function tail(string $file, int $bytes = 200): string
    {
        $handle = fopen($file, 'rb');
        fseek($handle, -min($bytes, filesize($file)), SEEK_END);
        $tail = (string) fread($handle, $bytes);
        fclose($handle);

        return $tail;
    }

    /** gzip in 1 MB chunks, so large dumps never have to fit in memory. */
    private function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');
        chmod($target, 0600); // contains user data and password hashes

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);
    }

    /**
     * MySQL option file with the credentials, readable only by this user.
     * Passing -p{password} instead would show the password to anyone running `ps`.
     */
    private function writeCredentialsFile(array $connection): string
    {
        $quote = fn ($value) => '"'.addcslashes((string) $value, "\\\"").'"';

        $file = tempnam(sys_get_temp_dir(), 'alodium-mysql-');
        chmod($file, 0600);
        file_put_contents($file, implode("\n", [
            '[client]',
            'user='.$quote($connection['username']),
            'password='.$quote($connection['password']),
            'host='.$quote($connection['host']),
            'port='.(int) $connection['port'],
            '',
        ]));

        return $file;
    }

    /** alodium-backup-2026-10-19-0200.sql.gz; seconds are added if that minute is taken (manual runs). */
    private function newBackupPath(string $directory): string
    {
        $path = $directory.'/alodium-backup-'.now()->format('Y-m-d-Hi').'.sql.gz';

        return file_exists($path)
            ? $directory.'/alodium-backup-'.now()->format('Y-m-d-His').'.sql.gz'
            : $path;
    }

    /** Keeps the newest $keep backups. The date format makes name order equal date order. */
    private function prune(string $directory, int $keep): void
    {
        $backups = glob($directory.'/alodium-backup-*.sql.gz') ?: [];
        rsort($backups); // newest first

        $old = array_slice($backups, max($keep, 1));
        foreach ($old as $file) {
            unlink($file);
        }

        if ($old !== []) {
            Log::info('Old database backups pruned.', ['deleted' => count($old), 'kept' => count($backups) - count($old)]);
            $this->info('Pruned '.count($old).' old backup(s).');
        }
    }

    private function failed(string $message): int
    {
        Log::error($message);
        $this->error($message);

        return self::FAILURE;
    }
}
