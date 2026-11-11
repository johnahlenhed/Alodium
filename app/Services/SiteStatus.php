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

    /**
     * Hours since the first account was created: how long this site has been
     * set up. Null if it can't be told.
     */
    public function siteAgeHours(): ?float
    {
        try {
            $first = DB::table('users')->min('created_at');
        } catch (Throwable) {
            return null;
        }

        // A first account "in the future" (a clock set back) counts as just created.
        return $first ? round(max(0, CarbonImmutable::parse($first)->diffInMinutes(now(), false)) / 60, 1) : null;
    }

    /**
     * The dashboard's notices, warnings first. Admins see them, and on a client's
     * site the client is an admin too, so each one has two parts: `message` in
     * the site owner's words (no paths, commands or variable names), and `detail`
     * for the developer, shown folded away. `level` is "warning" for something
     * that needs fixing, "info" for something that is normal for now (such as no
     * backup yet on a site set up today).
     *
     * @return array{lastRebuild: ?array, lastBackup: ?array, offsite: array, geoip: array, queuedJobs: ?int, failedJobs: ?int, notices: list<array{level: 'warning'|'info', message: string, detail: string}>}
     */
    public function summary(): array
    {
        $rebuild = $this->lastRebuild();
        $backup = $this->lastBackup();
        $failed = $this->countRows('failed_jobs');
        // The first backup runs at 02:00 UTC after setup: until then, none is normal.
        $siteAge = $this->siteAgeHours();
        $isNew = $siteAge !== null && $siteAge <= self::BACKUP_MAX_AGE_HOURS;
        $maxHours = self::BACKUP_MAX_AGE_HOURS;

        $notices = [];
        $add = function (string $level, string $message, string $detail) use (&$notices) {
            $notices[] = ['level' => $level, 'message' => $message, 'detail' => $detail];
        };

        if (($rebuild['status'] ?? null) === 'failed') {
            $add('warning', "Your latest changes didn't reach the public site: updating it failed. Visitors still see the previous version.",
                'The last rebuild failed (storage/logs/astro-rebuild.log; the build output is in storage/logs/laravel-*.log). A failed build is retried once; publishing or saving the settings starts a new one.');
        }
        if ($failed > 0) {
            $add('warning', $failed === 1 ? '1 background task failed, such as updating the public site or copying images.' : "{$failed} background tasks failed, such as updating the public site or copying images.",
                "{$failed} ".($failed === 1 ? 'row' : 'rows').' in failed_jobs. List them with php artisan queue:failed, then php artisan queue:retry all or queue:flush.');
        }
        if ($backup !== null && $backup['age_hours'] > $maxHours) {
            $add('warning', "The automatic backup of your site hasn't run for more than a day and a half.",
                "The newest file in storage/app/backups is more than {$maxHours} hours old. db:backup runs daily at 02:00 UTC from the scheduler: check www-data's crontab (README step 8) and storage/logs/laravel-*.log.");
        }
        // On a live site no backup at all is a problem, unless it was set up today;
        // locally it usually just means none was made.
        if ($backup === null && app()->isProduction()) {
            $isNew
                ? $add('info', "Your site's first automatic backup is made overnight, at 02:00 UTC.",
                    'No file in storage/app/backups yet, as expected on a new site. db:backup runs daily from the scheduler (README step 8).')
                : $add('warning', 'No backup of your site has been made yet.',
                    "No file in storage/app/backups, and the site was set up more than {$maxHours} hours ago. db:backup should run daily at 02:00 UTC: check the scheduler (README step 8) and storage/logs/laravel-*.log.");
        }

        $offsite = $this->offsite();
        if ($offsite['configured'] && $offsite['lastUploadAt'] === null) {
            $isNew
                ? $add('info', 'The first copy of your backups to off-site storage is made overnight, with the first backup.',
                    'Off-server copies are configured (BACKUP_S3_*); db:backup uploads after each nightly backup. Nothing uploaded yet, as expected on a new site.')
                : $add('warning', "Your backups aren't being copied to off-site storage.",
                    'Off-server copies are configured (BACKUP_S3_*), but no successful upload has been recorded. php artisan db:backup-test-remote shows what fails.');
        } elseif ($offsite['configured'] && $offsite['ageHours'] > $maxHours) {
            $add('warning', "Your backups haven't been copied to off-site storage for more than a day and a half.",
                "The last off-server upload is more than {$maxHours} hours old. Check storage/logs/laravel-*.log; sudo -u www-data php artisan db:backup-test-remote shows what fails.");
        }

        // In every environment: without the file, visitor countries are silently empty.
        $geoip = $this->geoip();
        if (! $geoip['present']) {
            // Normal until the developer installs it; nothing else is affected.
            $add('info', "Visitor countries aren't shown yet. The other visitor statistics are recorded as normal.",
                'The GeoIP database is missing (storage/app/geoip/GeoLite2-Country.mmdb), so visits are recorded without a country. Install it as in README deployment step 6, or with deploy/add-domain.sh geoip.');
        } elseif (! $geoip['readable']) {
            $add('warning', "Visitor countries can't be looked up at the moment. The other visitor statistics are recorded as normal.",
                "The GeoIP database can't be read, so visits are recorded without a country. Download it again with: php artisan location:update");
        } elseif ($geoip['ageHours'] > self::GEOIP_MAX_AGE_DAYS * 24) {
            $add('warning', 'The country lookup for visitors is out of date, so some countries may be wrong.',
                'The GeoIP database has not been updated for more than '.self::GEOIP_MAX_AGE_DAYS.' days. Check MAXMIND_LICENSE_KEY and the scheduler (README steps 6 and 8).');
        }

        // Warnings first; otherwise in the order above.
        usort($notices, fn ($a, $b) => ($a['level'] === 'warning' ? 0 : 1) <=> ($b['level'] === 'warning' ? 0 : 1));

        return [
            'lastRebuild' => $rebuild,
            'lastBackup' => $backup,
            'offsite' => $offsite,
            'geoip' => $geoip,
            'queuedJobs' => $this->countRows('jobs'),
            'failedJobs' => $failed,
            'notices' => $notices,
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
