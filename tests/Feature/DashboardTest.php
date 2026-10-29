<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\Submission;
use App\Models\User;
use App\Models\Visit;
use App\Services\OffsiteBackup;
use App\Services\SiteStatus;
use App\Support\TrafficSource;
use App\Support\UrlToContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ONLY = ['visitors', 'topContent', 'sources', 'siteStatus'];

    private User $admin;

    private User $editor;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Ada']);
        $this->editor = User::factory()->editor()->create(['name' => 'Ed']);

        // Never read or write the real log or backups.
        $this->dir = sys_get_temp_dir().'/alodium-dashboard-test-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/backups');
        File::ensureDirectoryExists($this->dir.'/geoip');
        config([
            'services.astro.rebuild_log' => $this->dir.'/astro-rebuild.log',
            'database.backups.path' => $this->dir.'/backups',
            'app.public_site_url' => 'https://example.com',
            'location.maxmind.local.path' => $this->dir.'/geoip/GeoLite2-Country.mmdb',
        ]);
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        // A healthy site has the GeoIP database (MaxMind's test file), updated recently.
        $this->installGeoIp(daysAgo: 3);
    }

    private function installGeoIp(int $daysAgo): void
    {
        $path = config('location.maxmind.local.path');
        copy(__DIR__.'/../Fixtures/geoip/GeoIP2-Country-Test.mmdb', $path);
        // The test's clock (travelTo), not the real time the file was copied.
        touch($path, now()->subDays($daysAgo)->getTimestamp());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function visit(string $at, array $attributes = []): void
    {
        $visit = new Visit(['url' => '/', ...$attributes]);
        $visit->created_at = Carbon::parse($at, 'UTC');
        $visit->save();
    }

    private function content(string $model, array $attributes): Post|Page
    {
        return $model::create([
            'author_id' => $this->admin->id, 'title' => 'T', 'slug' => 's'.uniqid(), 'status' => 'published',
            'published_at' => now()->subDay(), 'meta_description' => 'Has one', ...$attributes,
        ]);
    }

    // ------------------------------------------------------------ access and roles

    public function test_guests_are_redirected(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_admins_get_every_section(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('userName', 'Ada')
            ->where('publicSiteUrl', 'https://example.com')
            ->has('content')
            ->has('submissions')
            ->has('visitors')
            ->has('topContent')
            ->has('sources')
            ->has('siteStatus'));
    }

    public function test_editors_get_no_visitor_source_or_system_data(): void
    {
        $this->visit('2026-10-22 10:00', ['utm_source' => 'google']);

        $response = $this->actingAs($this->editor)->get('/dashboard')->assertOk();

        $response->assertInertia(function ($page) {
            $page->where('userName', 'Ed')->has('content')->has('submissions');
            foreach (self::ADMIN_ONLY as $key) {
                $page->missing($key);
            }
        });
        // Not even in the raw page data (e.g. through a renamed key).
        $json = json_encode($response->viewData('page')['props']);
        foreach (['pageViews', 'google', 'failedJobs', 'lastBackup', 'avgScrollDepth'] as $leak) {
            $this->assertStringNotContainsString($leak, $json);
        }
    }

    public function test_view_site_link_is_hidden_when_public_site_url_is_unset(): void
    {
        config(['app.public_site_url' => null]);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('publicSiteUrl', null));
    }

    public function test_an_empty_database_renders(): void
    {
        $this->actingAs($this->admin)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('visitors.pageViews', 0)
            ->where('visitors.change', null)
            ->where('visitors.isNew', false)
            ->where('visitors.avgDuration', 0)
            ->has('visitors.daily', 30)
            ->where('topContent', [])
            ->where('sources', [])
            ->where('content.publishedPosts', 0)
            ->where('content.nextScheduledAt', null)
            ->where('content.missingMeta', [])
            ->where('submissions.unread', 0)
            ->where('submissions.latest', [])
            ->where('siteStatus.lastRebuild', null)
            ->where('siteStatus.lastBackup', null)
            ->where('siteStatus.warnings', []));
    }

    public function test_query_count_for_a_dashboard_load(): void
    {
        $this->visit('2026-10-22 10:00', ['url' => '/blog/x/']);
        $this->actingAs($this->admin);

        DB::enableQueryLog();
        $this->get('/dashboard')->assertOk();
        $count = count(DB::getQueryLog());

        fwrite(STDERR, "\n[dashboard] queries for an admin page load: {$count}\n");
        $this->assertLessThanOrEqual(25, $count);
    }

    // ------------------------------------------------------------------ visitors

    public function test_period_and_change_against_the_previous_period(): void
    {
        foreach (['2026-10-22 09:00', '2026-10-21 09:00', '2026-10-17 09:00'] as $at) {
            $this->visit($at, ['duration' => 80, 'scroll_depth' => 60]);
        }
        $this->visit('2026-10-12 09:00'); // previous 7-day period (2026-10-09 .. 10-15)
        $this->visit('2026-10-10 09:00');

        $this->actingAs($this->admin)->get('/dashboard?days=7')->assertInertia(fn ($page) => $page
            ->where('visitors.days', 7)
            ->where('visitors.pageViews', 3)
            ->where('visitors.previousPageViews', 2)
            ->where('visitors.change', 50)
            ->where('visitors.isNew', false)
            ->where('visitors.avgDuration', 80)
            ->where('visitors.avgScrollDepth', 60)
            ->has('visitors.daily', 7)
            ->where('visitors.daily.6', ['date' => '2026-10-22', 'count' => 1])
            ->where('visitors.timezone', 'UTC'));
    }

    public function test_a_previous_period_of_zero_is_new_not_a_percentage(): void
    {
        $this->visit('2026-10-22 09:00');

        $this->actingAs($this->admin)->get('/dashboard?days=30')->assertInertia(fn ($page) => $page
            ->where('visitors.previousPageViews', 0)
            ->where('visitors.change', null)
            ->where('visitors.isNew', true));
    }

    public function test_unknown_periods_fall_back_to_30_days(): void
    {
        $this->actingAs($this->admin)->get('/dashboard?days=9999')->assertInertia(fn ($page) => $page->where('visitors.days', 30));
    }

    // ---------------------------------------------------------------- UrlToContent

    public function test_url_to_content(): void
    {
        $post = $this->content(Post::class, ['slug' => 'hello-world', 'title' => 'Hello World']);
        $page = $this->content(Page::class, ['slug' => 'about', 'title' => 'About us']);

        DB::enableQueryLog();
        $resolved = app(UrlToContent::class)->resolve([
            '/blog/hello-world/', '/about', '/', '/blog/', '/contact?x=1', '/about/?utm_source=x', '/missing-page', '/a/b/c', '',
        ]);
        $queries = count(DB::getQueryLog());

        $this->assertSame(['title' => 'Hello World', 'type' => 'post', 'id' => $post->id], $resolved['/blog/hello-world']);
        $this->assertSame(['title' => 'About us', 'type' => 'page', 'id' => $page->id], $resolved['/about']);
        $this->assertSame('Home', $resolved['/']['title']);
        $this->assertSame('Blog', $resolved['/blog']['title']);
        $this->assertSame('Contact', $resolved['/contact']['title']);
        $this->assertSame(['title' => '/missing-page', 'type' => 'other', 'id' => null], $resolved['/missing-page']);
        $this->assertSame('/a/b/c', $resolved['/a/b/c']['title']);
        $this->assertCount(7, $resolved); // trailing slash and query string variants collapse
        $this->assertSame(1, $queries);   // one query for all slugs
    }

    public function test_top_content_merges_trailing_slash_variants_and_shows_titles(): void
    {
        $post = $this->content(Post::class, ['slug' => 'hello', 'title' => 'Hello post']);
        $this->visit('2026-10-22 09:00', ['url' => '/blog/hello/']);
        $this->visit('2026-10-22 09:01', ['url' => '/blog/hello']);
        $this->visit('2026-10-22 09:02', ['url' => '/']);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('topContent.0', ['path' => '/blog/hello', 'title' => 'Hello post', 'type' => 'post', 'count' => 2, 'editUrl' => route('posts.edit', $post->id)])
            ->where('topContent.1.title', 'Home'));
    }

    // -------------------------------------------------------------------- sources

    public function test_traffic_source_labels(): void
    {
        $this->assertSame('newsletter', TrafficSource::label('newsletter', 'https://www.google.com/', null)); // utm wins
        $this->assertSame('google.com', TrafficSource::label(null, 'https://www.google.com/search?q=x'));
        $this->assertSame('google.com', TrafficSource::label(null, 'google.com'));
        $this->assertSame('Direct', TrafficSource::label(null, null));
        $this->assertSame('Direct', TrafficSource::label('', ''));
        $this->assertSame(TrafficSource::INTERNAL, TrafficSource::label(null, 'https://example.com/blog/', 'https://www.example.com'));
    }

    public function test_sources_merge_the_same_host_across_urls(): void
    {
        $this->visit('2026-10-22 09:00', ['referrer' => 'https://news.example.org/a']);
        $this->visit('2026-10-22 09:01', ['referrer' => 'https://news.example.org/b?x=1']);
        $this->visit('2026-10-22 09:02', ['referrer' => 'https://www.news.example.org/']);
        $this->visit('2026-10-22 09:03', ['utm_source' => 'newsletter', 'referrer' => 'https://news.example.org/c']);
        $this->visit('2026-10-22 09:04');

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('sources', [
            ['label' => 'news.example.org', 'count' => 3],
            ['label' => 'Direct', 'count' => 1],     // equal counts: sorted by name
            ['label' => 'newsletter', 'count' => 1],
        ]));
    }

    // -------------------------------------------------------------------- content

    public function test_content_counts_next_scheduled_and_missing_meta(): void
    {
        $this->content(Post::class, ['title' => 'Good post']);
        $noMeta = $this->content(Post::class, ['title' => 'No meta', 'meta_description' => null]);
        $this->content(Page::class, ['title' => 'Empty meta page', 'meta_description' => '']);
        $this->content(Post::class, ['title' => 'Draft', 'status' => 'draft', 'published_at' => null, 'meta_description' => null]);
        $this->content(Page::class, ['title' => 'Later', 'status' => 'draft', 'published_at' => null, 'publish_at' => '2026-10-25 08:00:00']);
        $this->content(Post::class, ['title' => 'Sooner', 'status' => 'draft', 'published_at' => null, 'publish_at' => '2026-10-23 07:30:00']);

        $this->actingAs($this->editor)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('content.publishedPosts', 2)
            ->where('content.publishedPages', 1)
            ->where('content.drafts', 1)
            ->where('content.scheduled', 2)
            ->where('content.nextScheduledAt', '2026-10-23T07:30:00+00:00')
            ->where('content.missingMetaCount', 2) // drafts don't count
            ->has('content.missingMeta', 2)
            ->where('content.missingMeta', fn ($rows) => collect($rows)->pluck('editUrl')->contains(route('posts.edit', $noMeta->id))));
    }

    public function test_missing_meta_list_is_capped_at_five(): void
    {
        foreach (range(1, 7) as $i) {
            $this->content(Post::class, ['title' => "No meta {$i}", 'meta_description' => null]);
        }

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('content.missingMetaCount', 7)
            ->has('content.missingMeta', 5));
    }

    // ---------------------------------------------------------------- submissions

    public function test_submissions_unread_count_latest_three_and_raw_text(): void
    {
        foreach (range(1, 4) as $i) {
            $this->travel(1)->minute();
            Submission::create(['name' => "Person {$i}", 'email' => 'a@example.com', 'message' => "Message {$i}", 'read' => $i === 1]);
        }
        $this->travel(1)->minute();
        Submission::create(['name' => '<script>alert(1)</script>', 'email' => 'x@example.com', 'message' => '<img src=x onerror=alert(2)> '.str_repeat('long ', 40)]);

        $response = $this->actingAs($this->editor)->get('/dashboard');
        $response->assertInertia(fn ($page) => $page
            ->where('submissions.unread', 4)
            ->has('submissions.latest', 3)
            ->where('submissions.latest.0.name', '<script>alert(1)</script>') // plain text; React escapes it
            ->where('submissions.latest.0.excerpt', fn ($excerpt) => mb_strlen($excerpt) <= 83 && str_ends_with($excerpt, '...'))
            ->where('submissions.latest.1.name', 'Person 4'));

        $this->assertStringNotContainsString('<script>alert(1)', $response->getContent());
        $this->assertStringNotContainsString('dangerouslySetInnerHTML', file_get_contents(resource_path('js/Pages/Dashboard.jsx')));
    }

    // ---------------------------------------------------------------- site status

    private function log(string $contents): void
    {
        file_put_contents(config('services.astro.rebuild_log'), $contents);
    }

    public function test_rebuild_log_ending_in_complete(): void
    {
        $this->log("Rebuild started at Thu Oct 22 01:59:00 UTC 2026\nRebuild FAILED at Thu Oct 22 01:59:30 UTC 2026\nRebuild started at Thu Oct 22 02:00:00 UTC 2026\nRebuild complete at Thu Oct 22 02:00:05 UTC 2026\n");

        $rebuild = app(SiteStatus::class)->lastRebuild();

        $this->assertSame('complete', $rebuild['status']);
        $this->assertSame('2026-10-22T02:00:05+00:00', $rebuild['at']);
        $this->assertSame([], app(SiteStatus::class)->summary()['warnings']);

        // A wrong weekday doesn't shift the date (2026-10-22 is a Thursday).
        $this->log("Rebuild complete at Wed Oct 22 02:00:05 UTC 2026\n");
        $this->assertSame('2026-10-22T02:00:05+00:00', app(SiteStatus::class)->lastRebuild()['at']);
        // Single-digit days are space-padded by `date`.
        $this->log("Rebuild complete at Fri Oct  2 02:00:05 UTC 2026\n");
        $this->assertSame('2026-10-02T02:00:05+00:00', app(SiteStatus::class)->lastRebuild()['at']);
    }

    public function test_rebuild_log_ending_in_failed_gives_a_warning(): void
    {
        $this->log("Rebuild complete at Tue Oct 21 02:00:05 UTC 2026\nRebuild started at Wed Oct 22 02:00:00 UTC 2026\nRebuild FAILED at Wed Oct 22 02:00:09 UTC 2026\n");

        $summary = app(SiteStatus::class)->summary();

        $this->assertSame('failed', $summary['lastRebuild']['status']);
        $this->assertStringContainsString('rebuild of the public site failed', $summary['warnings'][0]);
    }

    public function test_missing_log_and_malformed_date(): void
    {
        $this->assertNull(app(SiteStatus::class)->lastRebuild());

        $this->log("Rebuild complete at sometime yesterday-ish ???\n");
        $rebuild = app(SiteStatus::class)->lastRebuild();
        $this->assertSame('complete', $rebuild['status']);
        $this->assertNull($rebuild['at']);
        $this->assertSame('Rebuild complete at sometime yesterday-ish ???', $rebuild['line']); // shown raw

        $this->log("garbage\nnothing useful here\n");
        $this->assertNull(app(SiteStatus::class)->lastRebuild());
    }

    public function test_only_the_end_of_a_large_log_is_read(): void
    {
        // An old "complete" line far before the last 4 KB must not be found.
        $this->log("Rebuild complete at Mon Oct 20 02:00:00 UTC 2026\n".str_repeat("noise line\n", 1000));

        $this->assertNull(app(SiteStatus::class)->lastRebuild());
    }

    public function test_a_missing_geoip_database_warns_on_the_dashboard(): void
    {
        unlink(config('location.maxmind.local.path'));

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('siteStatus.geoip', ['present' => false, 'readable' => false, 'updatedAt' => null, 'ageHours' => null])
            ->where('siteStatus.warnings', ['The GeoIP database is missing, so visits are recorded without a country. Install it as described in the README (deployment step 6).']));
    }

    public function test_a_geoip_file_that_is_not_a_database_warns(): void
    {
        // For example an error page saved in its place, or a cut-off download.
        file_put_contents(config('location.maxmind.local.path'), '<html>401 Unauthorized</html>');

        $summary = app(SiteStatus::class)->summary();
        $this->assertSame(['present' => true, 'readable' => false, 'updatedAt' => null, 'ageHours' => null], $summary['geoip']);
        $this->assertStringContainsString("can't be read", $summary['warnings'][0]);
    }

    public function test_geoip_database_age_threshold(): void
    {
        $this->installGeoIp(daysAgo: 44);
        $summary = app(SiteStatus::class)->summary();
        $this->assertTrue($summary['geoip']['readable']);
        $this->assertSame('2026-09-08T12:00:00+00:00', $summary['geoip']['updatedAt']);
        $this->assertSame(44.0 * 24, $summary['geoip']['ageHours']);
        $this->assertSame([], $summary['warnings']);

        $this->installGeoIp(daysAgo: 46);
        $this->assertSame(
            ['The GeoIP database has not been updated for more than 45 days. Check MAXMIND_LICENSE_KEY and the scheduler.'],
            app(SiteStatus::class)->summary()['warnings'],
        );
    }

    public function test_backup_age_threshold_and_failed_jobs_warnings(): void
    {
        $file = config('database.backups.path').'/alodium-backup-2026-10-21-0200.sql.gz';
        file_put_contents($file, 'x');

        touch($file, now()->subHours(35)->getTimestamp());
        $this->assertSame([], app(SiteStatus::class)->summary()['warnings']);

        touch($file, now()->subHours(37)->getTimestamp());
        $summary = app(SiteStatus::class)->summary();
        $this->assertSame('alodium-backup-2026-10-21-0200.sql.gz', $summary['lastBackup']['file']);
        $this->assertGreaterThan(36, $summary['lastBackup']['age_hours']);
        $this->assertStringContainsString('more than 36 hours old', $summary['warnings'][0]);

        DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x']);
        $summary = app(SiteStatus::class)->summary();
        $this->assertSame(1, $summary['failedJobs']);
        $this->assertStringContainsString('1 queued job has failed', implode(' ', $summary['warnings']));
    }

    private function configureOffsite(): void
    {
        config([
            'filesystems.disks.backups.key' => 'k', 'filesystems.disks.backups.secret' => 's',
            'filesystems.disks.backups.bucket' => 'b', 'database.backups.passphrase' => 'p',
        ]);
    }

    private function recordUploadHoursAgo(float $hours): void
    {
        $this->travelTo(now()->subMinutes((int) ($hours * 60)));
        app(OffsiteBackup::class)->recordUpload('backups/x.sql.gz.enc');
        $this->travelBack();
        $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
    }

    private function freshBackup(): void
    {
        $file = config('database.backups.path').'/alodium-backup-2026-10-22-0200.sql.gz';
        file_put_contents($file, 'x');
        // The test's clock (travelTo), not the real time the file was written.
        touch($file, now()->subHours(10)->getTimestamp());
    }

    public function test_offsite_not_configured_gives_no_warning(): void
    {
        $this->freshBackup();

        $summary = app(SiteStatus::class)->summary();

        $this->assertFalse($summary['offsite']['configured']);
        $this->assertSame([], $summary['warnings']);
    }

    public function test_offsite_configured_but_never_uploaded_warns(): void
    {
        $this->freshBackup();
        $this->configureOffsite();

        $summary = app(SiteStatus::class)->summary();

        $this->assertNull($summary['offsite']['lastUploadAt']);
        $this->assertSame(['Off-server copies are configured, but no successful upload has been recorded.'], $summary['warnings']);
    }

    public function test_offsite_upload_age_threshold(): void
    {
        $this->freshBackup();
        $this->configureOffsite();

        $this->recordUploadHoursAgo(35);
        $summary = app(SiteStatus::class)->summary();
        $this->assertSame([], $summary['warnings']);
        $this->assertEqualsWithDelta(35, $summary['offsite']['ageHours'], 0.1);

        $this->recordUploadHoursAgo(37);
        $this->assertSame(['The last off-server copy was uploaded more than 36 hours ago.'], app(SiteStatus::class)->summary()['warnings']);
    }

    public function test_offsite_status_reaches_the_dashboard(): void
    {
        $this->freshBackup();
        $this->configureOffsite();
        $this->recordUploadHoursAgo(2);

        $this->actingAs($this->admin)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('siteStatus.offsite.configured', true)
            ->where('siteStatus.offsite.lastUploadAt', '2026-10-22T10:00:00+00:00')
            ->where('siteStatus.warnings', []));
    }

    public function test_no_backup_at_all_warns_only_in_production(): void
    {
        $this->assertSame([], app(SiteStatus::class)->summary()['warnings']); // local: "No data", no warning

        $this->app->detectEnvironment(fn () => 'production');
        $this->assertSame(['No database backup was found in storage/app/backups.'], app(SiteStatus::class)->summary()['warnings']);

        $this->freshBackup();
        $this->assertSame([], app(SiteStatus::class)->summary()['warnings']);
    }

    public function test_missing_tables_and_folders_give_no_data(): void
    {
        config(['database.backups.path' => $this->dir.'/does-not-exist']);
        $status = app(SiteStatus::class);

        $this->assertNull($status->lastBackup());
        $this->assertNull($status->countRows('no_such_table'));
    }

    public function test_offsite_flag_never_exposes_secrets(): void
    {
        config([
            'filesystems.disks.backups.key' => 'KEY-123', 'filesystems.disks.backups.secret' => 'SECRET-456',
            'filesystems.disks.backups.bucket' => 'bucket', 'database.backups.passphrase' => 'PASS-789',
        ]);

        $response = $this->actingAs($this->admin)->get('/dashboard');

        $response->assertInertia(fn ($page) => $page->where('siteStatus.offsite.configured', true));
        foreach (['KEY-123', 'SECRET-456', 'PASS-789'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_a_rebuild_line_with_a_trailing_newline_is_read(): void
    {
        $this->log("Rebuild complete at Thu Oct 22 02:00:05 UTC 2026\n\n");

        $this->assertSame('complete', app(SiteStatus::class)->lastRebuild()['status']);
    }

    public function test_a_path_with_a_trailing_newline_is_not_matched_to_content(): void
    {
        $this->content(Post::class, ['slug' => 'hello', 'title' => 'Hello post']);
        $this->content(Page::class, ['slug' => 'about', 'title' => 'About us']);

        $resolved = app(UrlToContent::class)->resolve(["/blog/hello\n", "/about\n", '/blog/hello']);

        // parse_url() turns the newline into "_" before the patterns see the path.
        $this->assertSame('/blog/hello_', UrlToContent::normalize("/blog/hello\n"));
        $this->assertSame('other', $resolved['/blog/hello_']['type']);
        $this->assertSame('other', $resolved['/about_']['type']);
        $this->assertSame('post', $resolved['/blog/hello']['type']);
    }
}
