<?php

namespace App\Console\Commands;

use App\Services\OffsiteBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks the off-server backup setup without making a real backup: encrypts a
 * small test file, uploads it, downloads and decrypts it, then deletes it.
 */
class TestRemoteBackup extends Command
{
    protected $signature = 'db:backup-test-remote';

    protected $description = 'Check that encrypted off-server backups can be uploaded, read back and deleted';

    public function handle(OffsiteBackup $offsite): int
    {
        if (! $offsite->isConfigured()) {
            $this->error('Not configured: set BACKUP_S3_KEY, BACKUP_S3_SECRET and BACKUP_S3_BUCKET (and BACKUP_S3_ENDPOINT for non-AWS providers).');

            return self::FAILURE;
        }
        if (! $offsite->hasPassphrase()) {
            $this->error('BACKUP_ENCRYPTION_PASSPHRASE is not set.');

            return self::FAILURE;
        }

        $id = Str::random(12);
        $plain = tempnam(sys_get_temp_dir(), 'alodium-remote-test-');
        $encrypted = $plain.'.enc';
        $downloaded = $plain.'.downloaded';
        $decrypted = $plain.'.decrypted';
        $fileName = "alodium-connection-test-{$id}.enc";
        $remotePath = $offsite->remotePath($fileName);
        $content = "Alodium off-server backup test {$id}\n";
        $uploaded = false;

        try {
            file_put_contents($plain, $content);

            $this->line('Encrypting test file...');
            $offsite->encrypt($plain, $encrypted);

            $this->line("Uploading to {$remotePath}...");
            $offsite->upload($encrypted, $fileName);
            $uploaded = true;

            $this->line('Reading it back and decrypting...');
            file_put_contents($downloaded, $offsite->disk()->get($remotePath));
            $offsite->decrypt($downloaded, $decrypted);
            if (file_get_contents($decrypted) !== $content) {
                throw new \RuntimeException('The downloaded file did not decrypt to the original content.');
            }

            $this->line('Deleting the test file...');
            $offsite->disk()->delete($remotePath);
            $uploaded = false;

            if ($offsite->disk()->exists($remotePath)) {
                throw new \RuntimeException("Deleted {$remotePath}, but it still exists. The key may lack delete permission, which pruning needs.");
            }
        } catch (Throwable $e) {
            $this->error('Off-server backup test FAILED: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if ($uploaded) {
                rescue(fn () => $offsite->disk()->delete($remotePath), report: false);
            }
            foreach ([$plain, $encrypted, $downloaded, $decrypted] as $file) {
                @unlink($file);
            }
        }

        $this->info('Off-server backups work: upload, download, decryption and deletion all succeeded.');

        return self::SUCCESS;
    }
}
