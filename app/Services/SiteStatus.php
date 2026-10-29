<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only health information for the dashboard. Every source is optional: a
 * missing log, folder or table gives null ("No data"), never an error.
 */
class SiteStatus
{
    public const BACKUP_MAX_AGE_HOURS = 36;

    /** The scheduler updates the GeoIP database monthly; older than this means it isn't. */
    public const GEOIP_MAX_AGE_DAYS = 45;

    /** How much of the end of the rebuild log is read. */
    private const LOG_TAIL_BYTES = 4096;

    public function __construct(private OffsiteBackup $offsite) {}

    /**
     * The last finished rebuild from storage/logs/astro-rebuild.log (written by
     * deploy/rebuild-astro.sh): "Rebuild complete at <date>" or "Rebuild FAILED at <date>".
     *
     * @return array{status: 'complete'|'failed', at: ?string, line: string}|null
     */
    public function lastRebuild(): ?array
    {
        $tail = $this->tail((string) config('services.astro.rebuild_log'));
        if ($tail === null) {
            return null;
        }

        foreach (array_reverse(preg_split('/\R/', $tail)) as $line) {
            if (preg_match('/^Rebuild (complete|FAILED) at (.+)\z/', trim($line), $m)) {
                return [
                    'status' => $m[1] === 'complete' ? 'complete' : 'failed',
                    'at' => $this->parseDate($m[2]),
                    'line' => trim($line),
                ];
            }
        }

        return null;
    }

    /** @return array{file: string, at: string, age_hours: float}|null */
    public function lastBackup(): ?array
    {
        $directory = (string) config('database.backups.path');
        $files = is_dir($directory) ? (glob($directory.'/alodium-backup-*.sql.gz') ?: []) : [];
        if (! $files) {
            return null;
        }

        rsort($files); // names start with the date
        $modified = CarbonImmutable::createFromTimestamp(filemtime($files[0]));

        return [
            'file' => basename($files[0]),
            'at' => $modified->toIso8601String(),
            'age_hours' => round($modified->diffInMinutes(now(), true) / 60, 1),
        ];
    }

    /**
     * Off-server copies: whether they are set up (never the values) and when the
     * last verified upload happened.
     *
     * @return array{configured: bool, lastUploadAt: ?string, ageHours: ?float}
     */
    public function offsite(): array
    {
        $last = $this->offsite->lastUpload();
        $at = null;
        try {
            $at = $last ? CarbonImmutable::parse($last['at']) : null;
        } catch (Throwable) {
            // An unreadable record counts as no upload.
        }

        return [
            'configured' => $this->offsiteConfigured(),
            'lastUploadAt' => $at?->toIso8601String(),
            'ageHours' => $at ? round($at->diffInMinutes(now(), true) / 60, 1) : null,
        ];
    }

    /** Whether off-server copies are set up (bucket and passphrase). Never exposes the values. */
    public function offsiteConfigured(): bool
    {
        return $this->offsite->isConfigured() && $this->offsite->hasPassphrase();
    }

    /**
     * The GeoIP country database (config location.maxmind.local.path): whether it
     * is there, whether it opens as a GeoIP database, and when it was last written
     * (by `location:update`). Without a readable file every visit is recorded
     * without a country, and nothing else says so.
     *
     * @return array{present: bool, readable: bool, updatedAt: ?string, ageHours: ?float}
     */
    public function geoip(): array
    {
        $path = (string) config('location.maxmind.local.path');
        if ($path === '' || ! is_file($path)) {
            return ['present' => false, 'readable' => false, 'updatedAt' => null, 'ageHours' => null];
        }

        try {
            // The reader checks the file's metadata, so a truncated or wrong file fails here.
            (new Reader($path))->close();
        } catch (Throwable) {
            return ['present' => true, 'readable' => false, 'updatedAt' => null, 'ageHours' => null];
        }

        $modified = CarbonImmutable::createFromTimestamp(filemtime($path));

        return [
            'present' => true,
            'readable' => true,
            'updatedAt' => $modified->toIso8601String(),
            'ageHours' => round($modified->diffInMinutes(now(), true) / 60, 1),
        ];
    }

    /** Rows in a table, or null if it doesn't exist or can't be read. */
    public function countRows(string $table): ?int
    {
        try {
            return DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{lastRebuild: ?array, lastBackup: ?array, offsite: array, geoip: array, queuedJobs: ?int, failedJobs: ?int, warnings: list<string>} */
    public function summary(): array
    {
        $rebuild = $this->lastRebuild();
        $backup = $this->lastBackup();
        $failed = $this->countRows('failed_jobs');

        $warnings = [];
        if (($rebuild['status'] ?? null) === 'failed') {
            $warnings[] = 'The last rebuild of the public site failed. The site still shows the previous version.';
        }
        if ($failed > 0) {
            $warnings[] = "{$failed} queued ".($failed === 1 ? 'job has' : 'jobs have').' failed.';
        }
        if ($backup !== null && $backup['age_hours'] > self::BACKUP_MAX_AGE_HOURS) {
            $warnings[] = 'The last database backup is more than '.self::BACKUP_MAX_AGE_HOURS.' hours old.';
        }
        // On a live site no backup at all is a problem; locally it usually just means none was made.
        if ($backup === null && app()->isProduction()) {
            $warnings[] = 'No database backup was found in storage/app/backups.';
        }

        $offsite = $this->offsite();
        if ($offsite['configured'] && $offsite['lastUploadAt'] === null) {
            $warnings[] = 'Off-server copies are configured, but no successful upload has been recorded.';
        } elseif ($offsite['configured'] && $offsite['ageHours'] > self::BACKUP_MAX_AGE_HOURS) {
            $warnings[] = 'The last off-server copy was uploaded more than '.self::BACKUP_MAX_AGE_HOURS.' hours ago.';
        }

        // In every environment: without the file, visitor countries are silently empty.
        $geoip = $this->geoip();
        if (! $geoip['present']) {
            $warnings[] = 'The GeoIP database is missing, so visits are recorded without a country. Install it as described in the README (deployment step 6).';
        } elseif (! $geoip['readable']) {
            $warnings[] = "The GeoIP database can't be read, so visits are recorded without a country. Download it again with: php artisan location:update";
        } elseif ($geoip['ageHours'] > self::GEOIP_MAX_AGE_DAYS * 24) {
            $warnings[] = 'The GeoIP database has not been updated for more than '.self::GEOIP_MAX_AGE_DAYS.' days. Check MAXMIND_LICENSE_KEY and the scheduler.';
        }

        return [
            'lastRebuild' => $rebuild,
            'lastBackup' => $backup,
            'offsite' => $offsite,
            'geoip' => $geoip,
            'queuedJobs' => $this->countRows('jobs'),
            'failedJobs' => $failed,
            'warnings' => $warnings,
        ];
    }

    /** The last few KB of a file, without reading all of it. */
    private function tail(string $path): ?string
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        fseek($handle, -min(self::LOG_TAIL_BYTES, (int) $size), SEEK_END);
        $data = fread($handle, self::LOG_TAIL_BYTES);
        fclose($handle);

        return $data === false ? null : $data;
    }

    /** `date` output such as "Thu Oct 22 02:00:05 UTC 2026"; null if it can't be read. */
    private function parseDate(string $value): ?string
    {
        // The weekday is dropped: a parser that trusts it would move a wrong one to another date.
        $value = preg_replace('/^[A-Za-z]{3,9},?\s+/', '', trim($value));

        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }
}
