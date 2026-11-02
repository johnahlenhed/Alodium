<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * deploy/check-add-domain.sh: deploy/add-domain.sh must write and run exactly
 * what README.md sections 3 to 8 describe. The edited copies prove the check fails,
 * and fails on the right block, when either side changes alone.
 */
class AddDomainScriptTest extends TestCase
{
    private const ROOT = __DIR__.'/../..';

    /** @var list<string> */
    private array $copies = [];

    protected function tearDown(): void
    {
        array_map('unlink', $this->copies);
        parent::tearDown();
    }

    private function check(?string $readme = null, ?string $script = null): Process
    {
        $process = new Process(['bash', self::ROOT.'/deploy/check-add-domain.sh', $readme ?? self::ROOT.'/README.md', $script ?? self::ROOT.'/deploy/add-domain.sh']);
        $process->run();

        return $process;
    }

    /** A temporary copy of $file with $search replaced (it must occur exactly once). */
    private function editedCopy(string $file, string $search, string $replace): string
    {
        $content = file_get_contents(self::ROOT.'/'.$file);
        $this->assertSame(1, substr_count($content, $search), "\"{$search}\" occurs once in {$file}");

        $copy = tempnam(sys_get_temp_dir(), 'add-domain-check-');
        file_put_contents($copy, str_replace($search, $replace, $content));
        $this->copies[] = $copy;

        return $copy;
    }

    public function test_add_domain_writes_what_the_readme_describes(): void
    {
        $process = $this->check();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertSame(8, substr_count($process->getOutput(), 'ok    '));
    }

    public static function readmeEdits(): array
    {
        return [
            'an Nginx setting' => ['client_max_body_size 30M;', 'client_max_body_size 50M;', 'Nginx config', '-    client_max_body_size 50M;'],
            'an env line' => ['TRACKER_URL=https://ADMIN_DOMAIN/tracker.js', 'TRACKER_URL=https://ADMIN_DOMAIN/js/tracker.js', 'astro/.env.production', '-TRACKER_URL=https://admin.client.example/js/tracker.js'],
            'the Nginx block moved' => ['`/etc/nginx/sites-available/alodium`:', 'The configuration:', 'Nginx config', 'not found in'],
            'the MaxMind key line' => ['MAXMIND_LICENSE_KEY=your-key', 'MAXMIND_KEY=your-key', 'MAXMIND_LICENSE_KEY line', '-MAXMIND_KEY=your-key'],
            'a GeoIP download command' => ['sudo -u www-data php artisan location:update', 'sudo -u www-data php artisan geoip:update', 'GeoIP download commands', '-sudo -u www-data php artisan geoip:update'],
            'a Laravel domain value' => ['MAIL_FROM_ADDRESS=hello@SITE_DOMAIN', 'MAIL_FROM_ADDRESS=info@SITE_DOMAIN', 'Laravel .env domain values', '-MAIL_FROM_ADDRESS=info@client.example'],
            'the npm install command' => ["cd /var/www/alodium/astro\nnpm install --legacy-peer-deps", "cd /var/www/alodium/astro\nnpm ci", 'Astro npm install', 'not found in'],
            'a Supervisor setting' => ['stopwaitsecs=670', 'stopwaitsecs=60', 'Supervisor worker config', '-stopwaitsecs=60'],
            'the cron line' => ['* * * * * cd /var/www/alodium && php artisan schedule:run >> /dev/null 2>&1', '*/5 * * * * cd /var/www/alodium && php artisan schedule:run >> /dev/null 2>&1', 'cron line', 'not found in'],
        ];
    }

    #[DataProvider('readmeEdits')]
    public function test_a_readme_change_alone_fails_the_check(string $search, string $replace, string $block, string $expected): void
    {
        $process = $this->check(readme: $this->editedCopy('README.md', $search, $replace));
        $output = $process->getOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString("FAIL  {$block}", $output);
        $this->assertStringContainsString($expected, $output);
        $this->assertSame(7, substr_count($output, 'ok    '), 'only the edited block fails');
    }

    public function test_a_script_change_alone_fails_the_check(): void
    {
        $process = $this->check(script: $this->editedCopy('deploy/add-domain.sh', 'API_URL=https://%s/api/public', 'API_URL=https://%s/api'));
        $output = $process->getOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL  astro/.env.production', $output);
        $this->assertStringContainsString('+API_URL=https://admin.client.example/api', $output);
    }

    public function test_a_change_to_the_scripts_geoip_commands_fails_the_check(): void
    {
        $process = $this->check(script: $this->editedCopy('deploy/add-domain.sh', "'sudo -u www-data mkdir -p storage/app/geoip'", "'mkdir -p storage/app/geoip'"));
        $output = $process->getOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL  GeoIP download commands', $output);
        $this->assertStringContainsString('+mkdir -p storage/app/geoip', $output);
    }

    public function test_a_change_to_the_scripts_worker_config_fails_the_check(): void
    {
        $process = $this->check(script: $this->editedCopy('deploy/add-domain.sh', '--tries=3 --timeout=660', '--tries=3 --timeout=60'));
        $output = $process->getOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL  Supervisor worker config', $output);
        $this->assertStringContainsString('+command=php /var/www/alodium/artisan queue:work --sleep=3 --tries=3 --timeout=60', $output);
    }
}
