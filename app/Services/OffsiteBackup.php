<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Encrypted copies of database backups in S3-compatible object storage, so
 * backups survive the loss of the server. Used by db:backup and db:backup-test-remote.
 *
 * Encryption: OpenSSL AES-256-CBC with a PBKDF2-derived key (openssl enc -pbkdf2),
 * so a backup can be decrypted with nothing but the openssl command line tool:
 *
 *   openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -in FILE.sql.gz.enc -out FILE.sql.gz
 *
 * Only encrypted files ever leave the server: upload() refuses anything else.
 */
class OffsiteBackup
{
    /** PBKDF2 iterations. Must match the decrypt command in the README. */
    public const PBKDF2_ITERATIONS = 600000;

    /** True when the bucket credentials are set (the passphrase is checked separately). */
    public function isConfigured(): bool
    {
        $disk = config('filesystems.disks.backups');

        return filled($disk['key'] ?? null) && filled($disk['secret'] ?? null) && filled($disk['bucket'] ?? null);
    }

    public function hasPassphrase(): bool
    {
        return filled(config('database.backups.passphrase'));
    }

    /** "backups/alodium-backup-2026-10-21-0200.sql.gz.enc" */
    public function remotePath(string $fileName): string
    {
        return trim(config('database.backups.remote_prefix'), '/').'/'.$fileName;
    }

    /**
     * Encrypts $source into $target. The passphrase is handed to openssl through a
     * temporary owner-only file (-pass file:...), never as an argument, so it never
     * shows up in the process list (`ps`).
     */
    public function encrypt(string $source, string $target): void
    {
        $this->openssl(['-salt', '-in', $source, '-out', $target]);
    }

    public function decrypt(string $source, string $target): void
    {
        $this->openssl(['-d', '-in', $source, '-out', $target]);
    }

    /**
     * Uploads an encrypted file and checks that the stored object exists and has
     * the same size as the local file. Throws on any failure.
     */
    public function upload(string $encryptedFile, string $fileName): void
    {
        if (! $this->isEncrypted($encryptedFile)) {
            throw new RuntimeException('Refusing to upload a file that is not encrypted.');
        }

        $path = $this->remotePath($fileName);
        $stream = fopen($encryptedFile, 'rb');

        try {
            $this->disk()->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $this->disk()->exists($path)) {
            throw new RuntimeException("Upload could not be verified: {$path} does not exist in the bucket.");
        }

        $remoteSize = $this->disk()->size($path);
        $localSize = filesize($encryptedFile);
        if ($remoteSize !== $localSize) {
            throw new RuntimeException("Upload could not be verified: {$path} is {$remoteSize} bytes in the bucket, {$localSize} locally.");
        }
    }

    /**
     * Keeps the newest $keep remote backups. Names start with the date
     * (alodium-backup-YYYY-MM-DD-HHMM), so name order is date order.
     *
     * @return int Number of objects deleted.
     */
    public function prune(int $keep): int
    {
        $prefix = trim(config('database.backups.remote_prefix'), '/');

        $backups = array_values(array_filter(
            $this->disk()->files($prefix),
            fn (string $path) => preg_match('#/alodium-backup-[^/]+\.sql\.gz\.enc\z#', $path),
        ));
        rsort($backups);

        $old = array_slice($backups, max($keep, 1));
        if ($old !== []) {
            $this->disk()->delete($old);
        }

        return count($old);
    }

    /**
     * Records a verified upload in storage/app/backups/.offsite-last-upload.json.
     * A small file next to the backups: it survives deploys (storage/app is not in
     * git) and `php artisan cache:clear`, and needs no migration. Written to a
     * temporary file first and renamed, so a crash never leaves it half-written.
     */
    public function recordUpload(string $remotePath): void
    {
        $file = $this->lastUploadFile();
        $directory = dirname($file);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        $temporary = $file.'.tmp';
        file_put_contents($temporary, json_encode(['at' => now()->toIso8601String(), 'file' => $remotePath]));
        chmod($temporary, 0600);
        rename($temporary, $file);
    }

    /** @return array{at: string, file: string}|null The last verified upload, or null if none is recorded. */
    public function lastUpload(): ?array
    {
        $file = $this->lastUploadFile();
        if (! is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) && is_string($data['at'] ?? null) ? ['at' => $data['at'], 'file' => (string) ($data['file'] ?? '')] : null;
    }

    private function lastUploadFile(): string
    {
        return rtrim((string) config('database.backups.path'), '/').'/.offsite-last-upload.json';
    }

    public function disk(): Filesystem
    {
        return Storage::disk('backups');
    }

    /** openssl enc writes "Salted__" as the first 8 bytes of every salted file. */
    private function isEncrypted(string $file): bool
    {
        $handle = fopen($file, 'rb');
        $magic = fread($handle, 8);
        fclose($handle);

        return $magic === 'Salted__';
    }

    private function openssl(array $arguments): void
    {
        $passphrase = (string) config('database.backups.passphrase');
        if ($passphrase === '') {
            throw new RuntimeException('BACKUP_ENCRYPTION_PASSPHRASE is not set.');
        }
        if (str_contains($passphrase, "\n") || str_contains($passphrase, "\r")) {
            // openssl's -pass file: reads only the first line.
            throw new RuntimeException('BACKUP_ENCRYPTION_PASSPHRASE must be a single line.');
        }

        $passwordFile = tempnam(sys_get_temp_dir(), 'alodium-backup-pass-');
        chmod($passwordFile, 0600);
        file_put_contents($passwordFile, $passphrase);

        try {
            $result = Process::timeout(1800)->run([
                'openssl', 'enc', '-aes-256-cbc', '-pbkdf2', '-iter', (string) self::PBKDF2_ITERATIONS,
                ...$arguments,
                '-pass', "file:{$passwordFile}",
            ]);
        } finally {
            @unlink($passwordFile);
        }

        if (! $result->successful()) {
            throw new RuntimeException('openssl failed: '.(trim($result->errorOutput()) ?: "exit code {$result->exitCode()}"));
        }
    }
}
