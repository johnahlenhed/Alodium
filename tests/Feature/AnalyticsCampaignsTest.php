<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The Campaigns and Mediums tables on /analytics (VisitStatistics::campaigns(),
 * topBy('utm_medium')), and the page rendered with the props the server sends
 * (resources/js/Pages/Analytics/Index.check.mjs). Also runs the UTM rules check
 * for the tracker and the link builder (resources/js/utils/utm.check.mjs).
 */
class AnalyticsCampaignsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'UTC'));
    }

    /**
     * Page views stored through the tracker endpoint, as the real ones are, then
     * dated $at (created_at comes from the database's clock, which travelTo() doesn't move).
     */
    private function views(int $count, array $utm, string $at = '2026-11-11 09:00', string $url = '/offer/'): void
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->postJson('/api/collect', ['url' => $url, ...$utm])->assertOk()->json('visit_id');
        }
        Visit::whereIn('id', $ids)->update(['created_at' => Carbon::parse($at, 'UTC')]);
    }

    private function seedCampaigns(): void
    {
        $this->views(3, ['utm_source' => 'Newsletter', 'utm_medium' => 'Email', 'utm_campaign' => 'Autumn-Sale']);
        $this->views(1, ['utm_source' => 'newsletter', 'utm_medium' => 'email', 'utm_campaign' => 'autumn-sale']); // the same, in lowercase
        $this->views(2, ['utm_source' => 'facebook', 'utm_medium' => 'social', 'utm_campaign' => 'autumn-sale']);
        // No source or medium, and two pages with a view each: the first alphabetically, and one more.
        $this->views(1, ['utm_campaign' => 'launch'], url: '/launch/');
        $this->views(1, ['utm_campaign' => 'launch'], url: '/offer/');
        $this->views(1, ['utm_source' => 'partner.example', 'utm_medium' => 'referral']); // no campaign: Mediums only
        $this->views(2, ['utm_source' => 'google']); // source only: neither table
        $this->views(1, []); // untagged
        $this->views(5, ['utm_campaign' => 'old-campaign', 'utm_medium' => 'email'], '2026-10-01 09:00'); // before the period
    }

    private function analyticsProps(string $query = '?days=30'): array
    {
        $response = $this->actingAs(User::factory()->create())->get('/analytics'.$query)->assertOk();

        // Through JSON, as the browser gets them.
        return json_decode(json_encode($response->viewData('page')['props']), true);
    }

    public function test_campaigns_show_their_source_and_medium_most_views_first(): void
    {
        $this->seedCampaigns();

        $this->actingAs(User::factory()->create())->get('/analytics?days=30')->assertInertia(fn (Assert $page) => $page
            ->where('campaigns', [
                ['campaign' => 'autumn-sale', 'source' => 'newsletter', 'medium' => 'email', 'count' => 4, 'page' => '/offer', 'otherPages' => 0, 'editUrl' => null],
                ['campaign' => 'autumn-sale', 'source' => 'facebook', 'medium' => 'social', 'count' => 2, 'page' => '/offer', 'otherPages' => 0, 'editUrl' => null],
                ['campaign' => 'launch', 'source' => null, 'medium' => null, 'count' => 2, 'page' => '/launch', 'otherPages' => 1, 'editUrl' => null],
            ])
            ->where('topMediums', [
                ['label' => 'email', 'count' => 4],
                ['label' => 'social', 'count' => 2],
                ['label' => 'referral', 'count' => 1],
            ])
            ->where('publicSiteUrl', config('app.public_site_url')));
    }

    public function test_a_campaign_on_one_page_shows_that_page_linked_to_its_edit_screen(): void
    {
        $admin = User::factory()->create();
        $page = Page::create(['author_id' => $admin->id, 'title' => 'Our offer', 'slug' => 'offer', 'status' => 'published', 'published_at' => now()->subDay(), 'content' => '<p>x</p>']);
        $post = Post::create(['author_id' => $admin->id, 'title' => 'Hello', 'slug' => 'hello', 'status' => 'published', 'published_at' => now()->subDay(), 'content' => '<p>x</p>']);
        $this->views(3, ['utm_campaign' => 'autumn-sale', 'utm_source' => 'newsletter'], url: '/offer/');
        $this->views(1, ['utm_campaign' => 'autumn-sale', 'utm_source' => 'newsletter'], url: '/offer'); // the same page
        $this->views(2, ['utm_campaign' => 'blog-push'], url: '/blog/hello/');
        $this->views(1, ['utm_campaign' => 'home-launch'], url: '/');
        $this->views(1, ['utm_campaign' => 'gone'], url: '/no-such-page/');

        $campaigns = collect($this->analyticsProps()['campaigns'])->keyBy('campaign');

        $this->assertSame(['count' => 4, 'page' => '/offer', 'otherPages' => 0, 'editUrl' => route('pages.edit', $page)], collect($campaigns['autumn-sale'])->only(['count', 'page', 'otherPages', 'editUrl'])->all());
        $this->assertSame(['/blog/hello', 0, route('posts.edit', $post)], [$campaigns['blog-push']['page'], $campaigns['blog-push']['otherPages'], $campaigns['blog-push']['editUrl']]);
        // The home page and a path that is no post or page: shown, not linked.
        $this->assertSame(['/', null], [$campaigns['home-launch']['page'], $campaigns['home-launch']['editUrl']]);
        $this->assertSame(['/no-such-page', null], [$campaigns['gone']['page'], $campaigns['gone']['editUrl']]);
    }

    public function test_a_campaign_on_several_pages_shows_the_most_visited_and_how_many_more(): void
    {
        $utm = ['utm_campaign' => 'spring', 'utm_source' => 'newsletter', 'utm_medium' => 'email'];
        $this->views(3, $utm, url: '/a/');
        $this->views(1, $utm, url: '/a'); // with /a/: 4 views, the most
        $this->views(2, $utm, url: '/b/');
        $this->views(1, $utm, url: '/c/');
        // Equal views: the first path alphabetically, so the row is stable.
        $tie = ['utm_campaign' => 'tie', 'utm_source' => 'facebook'];
        $this->views(2, $tie, url: '/x/');
        $this->views(2, $tie, url: '/w/');
        // The same campaign from another source is its own row, with its own pages.
        $this->views(1, ['utm_campaign' => 'spring', 'utm_source' => 'facebook', 'utm_medium' => 'social'], url: '/c/');

        $rows = array_map(fn ($row) => [$row['campaign'], $row['source'], $row['count'], $row['page'], $row['otherPages']], $this->analyticsProps()['campaigns']);

        $this->assertSame([
            ['spring', 'newsletter', 7, '/a', 2],
            ['tie', 'facebook', 4, '/w', 1],
            ['spring', 'facebook', 1, '/c', 0],
        ], $rows);
    }

    public function test_the_period_picker_applies_to_both_tables(): void
    {
        $this->seedCampaigns();

        $props = $this->analyticsProps('?days=90');
        $this->assertSame(['old-campaign', 5], [$props['campaigns'][0]['campaign'], $props['campaigns'][0]['count']]);
        $this->assertSame(['label' => 'email', 'count' => 9], $props['topMediums'][0]);

        $props = $this->analyticsProps('?days=7');
        $this->assertNotContains('old-campaign', array_column($props['campaigns'], 'campaign'));
    }

    public function test_without_campaign_visits_both_tables_are_empty(): void
    {
        $this->views(2, ['utm_source' => 'google']);
        $this->views(1, []);

        $props = $this->analyticsProps();
        $this->assertSame([], $props['campaigns']);
        $this->assertSame([], $props['topMediums']);
    }

    public function test_at_most_ten_campaigns(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->views($i, ['utm_campaign' => "campaign-{$i}"]);
        }

        $campaigns = $this->analyticsProps()['campaigns'];
        $this->assertCount(10, $campaigns);
        $this->assertSame(['campaign-12', 12], [$campaigns[0]['campaign'], $campaigns[0]['count']]);
    }

    public function test_the_page_renders_the_tables_with_the_props_the_server_sends(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed.');
        }
        $this->seedCampaigns();

        $cases = [
            [
                'name' => 'with the props the server sends',
                'props' => $this->analyticsProps(),
                'contains' => ['Campaigns', 'Mediums', 'Campaign link builder'],
                'rows' => [
                    'Campaigns' => [['autumn-sale', 'newsletter', 'email', '/offer', '4'], ['autumn-sale', 'facebook', 'social', '/offer', '2'], ['launch', 'not set', 'not set', '/launch and 1 more page', '2']],
                    'Mediums' => [['email', '4'], ['social', '2'], ['referral', '1']],
                ],
            ],
        ];
        $dir = sys_get_temp_dir().'/alodium-analytics-render-'.uniqid();
        File::ensureDirectoryExists($dir);
        file_put_contents($dir.'/cases.json', json_encode($cases));

        try {
            $process = new Process([$node, base_path('resources/js/Pages/Analytics/Index.check.mjs'), $dir.'/cases.json'], base_path());
            $process->setTimeout(180);
            $process->run();
        } finally {
            File::deleteDirectory($dir);
        }

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertSame(1, preg_match_all('/^ok - /m', $process->getOutput()), $process->getOutput());
    }

    public function test_the_tracker_and_the_link_builder_follow_the_servers_rules(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('node is not installed.');
        }

        $process = new Process([$node, base_path('resources/js/utils/utm.check.mjs')], base_path());
        $process->setTimeout(60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertMatchesRegularExpression('/all \d+ checks passed/', $process->getOutput());
    }
}
