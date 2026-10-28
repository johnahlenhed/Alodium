<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * deploy/check-add-domain.sh: deploy/add-domain.sh must write exactly what
 * README.md sections 4 and 5 describe. The edited copies prove the check fails,
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
        $this->assertSame(2, substr_count($process->getOutput(), 'ok    '));
    }

    public static function readmeEdits(): array
    {
        return [
            'an Nginx setting' => ['client_max_body_size 30M;', 'client_max_body_size 50M;', 'Nginx config', '-    client_max_body_size 50M;'],
            'an env line' => ['TRACKER_URL=https://ADMIN_DOMAIN/tracker.js', 'TRACKER_URL=https://ADMIN_DOMAIN/js/tracker.js', 'astro/.env.production', '-TRACKER_URL=https://admin.client.example/js/tracker.js'],
            'the Nginx block moved' => ['`/etc/nginx/sites-available/alodium`:', 'The configuration:', 'Nginx config', 'not found in'],
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
        $this->assertSame(1, substr_count($output, 'ok    '), 'only the edited block fails');
    }

    public function test_a_script_change_alone_fails_the_check(): void
    {
        $process = $this->check(script: $this->editedCopy('deploy/add-domain.sh', 'API_URL=https://%s/api/public', 'API_URL=https://%s/api'));
        $output = $process->getOutput();

        $this->assertSame(1, $process->getExitCode(), $output);
        $this->assertStringContainsString('FAIL  astro/.env.production', $output);
        $this->assertStringContainsString('+API_URL=https://admin.client.example/api', $output);
    }
}
