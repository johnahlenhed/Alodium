<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Each role logs in, loads the dashboard, and the props the server sends are
 * rendered by the real Dashboard.jsx (resources/js/Pages/Dashboard.check.mjs).
 *
 * The production crash ("Cannot read properties of undefined (reading 'length')"
 * in Site status) was the admin assets being older than the server's PHP: the
 * PHP sent siteStatus.notices, the old page read siteStatus.warnings. A PHP test
 * alone can't see that, so this one renders the page too.
 */
class DashboardRenderTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // Site status reads these; never the real log, backups or GeoIP file.
        $this->dir = sys_get_temp_dir().'/alodium-dashboard-render-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/backups');
        config([
            'services.astro.rebuild_log' => $this->dir.'/astro-rebuild.log',
            'database.backups.path' => $this->dir.'/backups',
            'location.maxmind.local.path' => $this->dir.'/GeoLite2-Country.mmdb',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    /** The props the dashboard page gets, as the browser receives them. */
    private function dashboardProps(User $user): array
    {
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();

        // Through JSON, as the browser gets them (objects, nulls), not PHP arrays.
        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    public function test_each_role_gets_its_own_data(): void
    {
        $admin = $this->dashboardProps(User::factory()->create());
        $editor = $this->dashboardProps(User::factory()->editor()->create());

        foreach (['visitors', 'topContent', 'sources', 'siteStatus', 'gettingStarted'] as $adminOnly) {
            $this->assertArrayHasKey($adminOnly, $admin);
            // Deliberate: system data and site setup are admin-only (DashboardController::build).
            $this->assertArrayNotHasKey($adminOnly, $editor, "{$adminOnly} is admin-only");
        }
        // The shape Dashboard.jsx reads.
        $this->assertIsArray($admin['siteStatus']['notices']);
        $this->assertArrayNotHasKey('warnings', $admin['siteStatus']);
        foreach (['content', 'submissions', 'userName'] as $shared) {
            $this->assertArrayHasKey($shared, $admin);
            $this->assertArrayHasKey($shared, $editor);
        }
    }

    public function test_the_dashboard_renders_without_errors_for_every_role(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed.');
        }

        $cases = [
            [
                'name' => 'admin, with the props the server sends',
                'props' => $this->dashboardProps(User::factory()->create()),
                'contains' => ['Getting started', 'Page views', 'Site status', 'Content', 'Submissions'],
            ],
            [
                'name' => 'editor, with the props the server sends',
                'props' => $this->dashboardProps(User::factory()->editor()->create()),
                'contains' => ['Content', 'Submissions'],
                'lacks' => ['Getting started', 'Page views', 'Site status'],
            ],
        ];
        $file = $this->dir.'/cases.json';
        file_put_contents($file, json_encode($cases));

        $process = new Process([$node, base_path('resources/js/Pages/Dashboard.check.mjs'), $file], base_path());
        $process->setTimeout(180);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertSame(2, preg_match_all('/^ok - /m', $process->getOutput()), $process->getOutput());
    }
}
