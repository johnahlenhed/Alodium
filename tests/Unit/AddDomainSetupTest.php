<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * deploy/add-domain.sh setup, run for real against a scratch directory: as root
 * in a user namespace (unshare -r, so no real root is needed), with stub nginx,
 * systemctl, supervisorctl, crontab, npm and php that only record their calls
 * (crontabs are files in the sandbox). Skipped where user namespaces aren't
 * available.
 */
class AddDomainSetupTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    private const CRON = '* * * * * cd /var/www/alodium && php artisan schedule:run >> /dev/null 2>&1';

    private string $box;

    protected function setUp(): void
    {
        parent::setUp();

        $probe = new Process(['unshare', '-r', 'true']);
        $probe->run();
        if (! $probe->isSuccessful()) {
            $this->markTestSkipped('unshare -r (user namespaces) is not available here.');
        }

        $this->box = sys_get_temp_dir().'/add-domain-setup-'.bin2hex(random_bytes(6));
        foreach (['bin', 'cron', 'app/astro', 'app/storage/logs', 'app/bootstrap/cache', 'nginx/sites-available', 'nginx/sites-enabled', 'supervisor'] as $dir) {
            mkdir("{$this->box}/{$dir}", 0755, true);
        }
        file_put_contents("{$this->box}/app/astro/package.json", '{}');
        copy(self::ROOT.'/.env.example', "{$this->box}/app/.env.example");
        copy(self::ROOT.'/.env.example', "{$this->box}/app/.env");
        file_put_contents("{$this->box}/calls.log", '');

        $log = 'echo "$(basename "$0") $* | $PWD" >> "$SANDBOX/calls.log"';
        $this->stub('nginx', $log);
        $this->stub('systemctl', $log);
        $this->stub('supervisorctl', $log);
        $this->stub('php', $log);
        $this->stub('npm', $log."\nmkdir -p node_modules");
        $this->stub('crontab', <<<'BASH'
            user=root
            [[ $1 == -u ]] && { user=$2; shift 2; }
            file="$SANDBOX/cron/$user"
            case $1 in
                -l) [[ -f $file ]] && cat "$file" || { echo "no crontab for $user" >&2; exit 1; } ;;
                -) cat > "$file"; echo "crontab -u $user - | $PWD" >> "$SANDBOX/calls.log" ;;
            esac
            BASH);
    }

    protected function tearDown(): void
    {
        if (isset($this->box)) {
            (new Process(['rm', '-rf', '--', $this->box]))->run();
        }
        parent::tearDown();
    }

    private function stub(string $name, string $body): void
    {
        file_put_contents("{$this->box}/bin/{$name}", "#!/bin/bash\n{$body}\n");
        chmod("{$this->box}/bin/{$name}", 0755);
    }

    private function runSetup(string ...$options): Process
    {
        $process = new Process(['unshare', '-r', 'env', '-i',
            "PATH={$this->box}/bin:/usr/bin:/bin", "SANDBOX={$this->box}", "HOME={$this->box}",
            "ALODIUM_APP_DIR={$this->box}/app", "ALODIUM_NGINX_DIR={$this->box}/nginx",
            "ALODIUM_SUPERVISOR_DIR={$this->box}/supervisor", 'ALODIUM_WEB_USER=root',
            'bash', self::ROOT.'/deploy/add-domain.sh', 'setup',
            '--admin-domain', 'admin.client.example', '--site-domain', 'client.example', ...$options,
        ]);
        $process->run();

        return $process;
    }

    /** Every file in the sandbox (and where each link points), by content, except the call log. */
    private function snapshot(): array
    {
        $files = [];
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->box, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $path => $item) {
            $relative = substr($path, strlen($this->box) + 1);
            if ($relative !== 'calls.log') {
                $files[$relative] = $item->isLink() ? 'link:'.readlink($path) : ($item->isDir() ? 'dir' : md5_file($path));
            }
        }
        ksort($files);

        return $files;
    }

    private function calls(): array
    {
        return array_values(array_filter(explode("\n", file_get_contents("{$this->box}/calls.log"))));
    }

    private function envValue(string $name): ?string
    {
        return preg_match('/^'.$name.'=(.*)$/m', file_get_contents("{$this->box}/app/.env"), $m) ? $m[1] : null;
    }

    private function allOutput(Process $process): string
    {
        return $process->getOutput().$process->getErrorOutput();
    }

    public function test_setup_sets_the_domain_values_installs_the_worker_and_cron_and_runs_npm(): void
    {
        touch("{$this->box}/app/bootstrap/cache/config.php"); // the config is cached
        $before = file("{$this->box}/app/.env");

        $process = $this->runSetup();
        $this->assertSame(0, $process->getExitCode(), $this->allOutput($process));

        // The .env.example placeholders (localhost, hello@alodium.dev) and the empty value are replaced...
        $this->assertSame('https://admin.client.example', $this->envValue('APP_URL'));
        $this->assertSame('https://client.example', $this->envValue('PUBLIC_SITE_URL'));
        $this->assertSame('hello@client.example', $this->envValue('MAIL_FROM_ADDRESS'));
        // ...and nothing else in .env changes.
        $changed = array_diff_assoc(file("{$this->box}/app/.env"), $before);
        $this->assertSame(['APP_URL', 'MAIL_FROM_ADDRESS', 'PUBLIC_SITE_URL'], array_values(array_map(fn ($line) => strstr($line, '=', true), $changed)));

        // What README section 7 shows (deploy/check-add-domain.sh compares the two).
        $source = new Process(['bash', '-c', 'source "$1" && supervisor_content', 'bash', self::ROOT.'/deploy/add-domain.sh']);
        $source->run();
        $this->assertSame($source->getOutput()."\n", file_get_contents("{$this->box}/supervisor/alodium-worker.conf"));
        $this->assertFileExists("{$this->box}/app/storage/logs/worker.log");
        $this->assertSame(self::CRON."\n", file_get_contents("{$this->box}/cron/root"));

        $app = "{$this->box}/app";
        $this->assertSame([
            "nginx -t | {$app}",
            "systemctl reload nginx | {$app}",
            "supervisorctl reread | {$app}",
            "supervisorctl update | {$app}",
            "crontab -u root - | {$app}",
            "npm install --legacy-peer-deps | {$app}/astro",
            "php artisan config:cache | {$app}",
        ], array_map(fn ($call) => str_replace(self::ROOT, $app, $call), $this->callsFrom($app)));
    }

    /** The calls, with the working directory of those made from outside the app shown as the app (nginx etc. don't care). */
    private function callsFrom(string $app): array
    {
        return array_map(fn ($call) => preg_replace('/ \| (?!'.preg_quote($app, '/').').*$/', " | {$app}", $call), $this->calls());
    }

    public function test_running_setup_again_changes_nothing_and_adds_no_second_cron_line(): void
    {
        touch("{$this->box}/app/bootstrap/cache/config.php");
        $this->assertSame(0, $this->runSetup()->getExitCode());
        $after = $this->snapshot();
        file_put_contents("{$this->box}/calls.log", '');

        $process = $this->runSetup();

        $this->assertSame(0, $process->getExitCode(), $this->allOutput($process));
        $this->assertSame($after, $this->snapshot());
        $this->assertSame(1, substr_count(file_get_contents("{$this->box}/cron/root"), 'schedule:run'));
        $this->assertStringContainsString("Unchanged: the schedule:run line in root's crontab", $process->getOutput());
        $this->assertStringContainsString('Unchanged: APP_URL', $process->getOutput());
        $calls = implode("\n", $this->calls());
        $this->assertStringNotContainsString('crontab -u root -', $calls, 'The crontab is not written again.');
        $this->assertStringNotContainsString('config:cache', $calls, 'Nothing in .env changed, so the cache is left alone.');
    }

    public function test_values_of_their_own_are_kept_and_force_replaces_them(): void
    {
        $env = file_get_contents("{$this->box}/app/.env");
        $env = preg_replace('/^APP_URL=.*$/m', 'APP_URL=https://cms.client.example', $env);
        $env = preg_replace('/^MAIL_FROM_ADDRESS=.*$/m', 'MAIL_FROM_ADDRESS=info@client.example', $env);
        file_put_contents("{$this->box}/app/.env", $env);

        $process = $this->runSetup();

        $this->assertSame(0, $process->getExitCode(), $this->allOutput($process));
        $this->assertSame('https://cms.client.example', $this->envValue('APP_URL'));
        $this->assertSame('info@client.example', $this->envValue('MAIL_FROM_ADDRESS'));
        $this->assertSame('https://client.example', $this->envValue('PUBLIC_SITE_URL'), 'Empty, so set.');
        $this->assertStringContainsString('Kept APP_URL=https://cms.client.example', $process->getOutput());
        $this->assertStringContainsString('run again with --force to replace it', $process->getOutput());
        // The config isn't cached, so there is nothing to refresh.
        $this->assertStringNotContainsString('config:cache', implode("\n", $this->calls()));

        $forced = $this->runSetup('--force');

        $this->assertSame(0, $forced->getExitCode(), $this->allOutput($forced));
        $this->assertSame('https://admin.client.example', $this->envValue('APP_URL'));
        $this->assertSame('hello@client.example', $this->envValue('MAIL_FROM_ADDRESS'));
        $this->assertCount(1, glob("{$this->box}/app/.env.bak-*"), 'The old .env is kept.');
    }

    public function test_dry_run_changes_nothing_and_runs_nothing(): void
    {
        touch("{$this->box}/app/bootstrap/cache/config.php");
        $before = $this->snapshot();

        $process = $this->runSetup('--dry-run');

        $this->assertSame(0, $process->getExitCode(), $this->allOutput($process));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame([], $this->calls(), 'No stub was run, except crontab -l (not logged).');
        $output = $process->getOutput();
        foreach ([
            'would set APP_URL=https://admin.client.example',
            'would set MAIL_FROM_ADDRESS=hello@client.example',
            "would write {$this->box}/supervisor/alodium-worker.conf",
            'would run: supervisorctl reread',
            "would add to root's crontab: ".self::CRON,
            "would run (in {$this->box}/app/astro): npm install --legacy-peer-deps",
            "would run (in {$this->box}/app): php artisan config:cache",
        ] as $line) {
            $this->assertStringContainsString($line, $output);
        }
    }

    public function test_other_cron_lines_stay_and_a_different_schedule_line_needs_force(): void
    {
        $other = '0 3 * * * /usr/local/bin/other-job';
        file_put_contents("{$this->box}/cron/root", "{$other}\n*/5 * * * * cd /srv/app && php artisan schedule:run\n");
        $before = $this->snapshot();

        $refused = $this->runSetup();

        $this->assertSame(1, $refused->getExitCode());
        $this->assertStringContainsString("root's crontab runs schedule:run, but not exactly once", $this->allOutput($refused));
        $this->assertSame($before, $this->snapshot(), 'Refused before changing anything.');

        $forced = $this->runSetup('--force');

        $this->assertSame(0, $forced->getExitCode(), $this->allOutput($forced));
        $this->assertSame("{$other}\n".self::CRON."\n", file_get_contents("{$this->box}/cron/root"));
    }

    public function test_a_different_worker_config_needs_force(): void
    {
        file_put_contents("{$this->box}/supervisor/alodium-worker.conf", "[program:alodium-worker]\ncommand=php artisan queue:work\n");
        $before = $this->snapshot();

        $refused = $this->runSetup();

        $this->assertSame(1, $refused->getExitCode());
        $this->assertStringContainsString('alodium-worker.conf exists with other content', $this->allOutput($refused));
        $this->assertSame($before, $this->snapshot());

        $this->assertSame(0, $this->runSetup('--force')->getExitCode());
        $this->assertStringContainsString('--timeout=660', file_get_contents("{$this->box}/supervisor/alodium-worker.conf"));
        $this->assertCount(1, glob("{$this->box}/supervisor/alodium-worker.conf.bak-*"));
    }
}
