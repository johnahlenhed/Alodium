<?php

namespace Tests\Feature;

use App\Http\Requests\UpdateSiteSettingsRequest;
use App\Jobs\RebuildAstroJob;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PublicSiteBuilds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * "Write something, see it live": what saving a page says, whether the page is
 * live yet (PublicSiteBuilds, from what the last successful rebuild built), and
 * adding it to the menu from the page editor.
 */
class PagePublishFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $editor;

    private ?string $script = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->editor = User::factory()->editor()->create();
        config(['app.public_site_url' => 'https://client.example']);
        // Rebuilds are run by hand below, when the test says so.
        Queue::fake();
    }

    protected function tearDown(): void
    {
        if ($this->script !== null) {
            @unlink($this->script);
        }
        parent::tearDown();
    }

    /** A rebuild script that exists (the build itself is faked). */
    private function rebuildsConfigured(): void
    {
        $this->script = tempnam(sys_get_temp_dir(), 'rebuild');
        chmod($this->script, 0755);
        config(['services.astro.rebuild_script' => $this->script]);
    }

    /** One rebuild, as the queue worker runs it; $during runs while it "builds". */
    private function rebuild(bool $succeeds = true, ?callable $during = null): void
    {
        Process::fake(['*' => function () use ($succeeds, $during) {
            $during && $during();

            return $succeeds ? Process::result('ok') : Process::result('', 'build error', 1);
        }]);
        $job = new RebuildAstroJob;
        try {
            $job->handle(app(PublicSiteBuilds::class));
        } catch (RuntimeException $e) {
            $job->failed($e); // as if the retries were used up too
        }
    }

    private function page(array $attributes = []): Page
    {
        return Page::create([
            'author_id' => $this->admin->id, 'title' => 'About us', 'slug' => 'about-us', 'content' => '<p>Hi</p>',
            'status' => 'published', 'published_at' => now()->subMinute(), ...$attributes,
        ]);
    }

    private function state(Page $page): array
    {
        return app(PublicSiteBuilds::class)->pageStatus($page->fresh());
    }

    // ------------------------------------------------------------------ after saving

    public function test_creating_a_published_page_goes_to_its_editor_and_names_its_address(): void
    {
        $this->actingAs($this->admin)
            ->post('/pages', ['title' => 'About us', 'status' => 'published'])
            ->assertRedirect(route('pages.edit', Page::sole()))
            ->assertSessionHas('success', 'Page "About us" created. Its address is /about-us.');
    }

    public function test_a_draft_says_it_is_not_on_the_site_and_where_it_will_be(): void
    {
        $this->actingAs($this->admin)
            ->post('/pages', ['title' => 'Prices', 'status' => 'draft'])
            ->assertSessionHas('success', 'Page "Prices" created. It\'s a draft, so it isn\'t on the public site. When it\'s published, its address is /prices.');
    }

    public function test_renaming_says_the_address_changed(): void
    {
        $page = $this->page();

        $this->actingAs($this->admin)
            ->put("/pages/{$page->id}", ['title' => 'Who we are', 'status' => 'published'])
            ->assertRedirect(route('pages.edit', $page))
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Changes to "Who we are" saved. Its address is /who-we-are.')
                && str_contains($message, 'changed its address from /about-us'));
    }

    // ------------------------------------------------------------------ live or not

    public function test_a_published_page_is_pending_until_a_build_includes_it_then_live(): void
    {
        $this->rebuildsConfigured();
        $this->rebuild(); // an earlier build, before the page existed
        $page = $this->page();

        $this->assertSame('pending', $this->state($page)['state']);
        $this->assertFalse($this->state($page)['onSite'], 'Not built yet: no link to a page that isn\'t there.');

        $this->rebuild();

        $this->assertSame(['state' => 'live', 'path' => '/about-us', 'url' => 'https://client.example/about-us', 'onSite' => true, 'currentUrl' => 'https://client.example/about-us', 'publishAt' => null], $this->state($page));
    }

    public function test_an_edit_to_a_live_page_is_pending_with_the_previous_version_still_on_the_site(): void
    {
        $this->rebuildsConfigured();
        $page = $this->page();
        $this->rebuild();

        $this->travel(5)->seconds();
        $page->update(['title' => 'About us!']);

        $this->assertSame('pending', $this->state($page)['state']);
        $this->assertTrue($this->state($page)['onSite'], 'The previous version is still on the site.');

        $this->rebuild();
        $this->assertSame('live', $this->state($page)['state']);
    }

    /** Renaming moves the page: until the next build, the version on the site is at the old address. */
    public function test_after_a_rename_the_current_version_is_linked_at_its_old_address(): void
    {
        $this->rebuildsConfigured();
        $page = $this->page();
        $this->rebuild();
        $this->travel(5)->seconds();

        $this->actingAs($this->admin)->put("/pages/{$page->id}", ['title' => 'Who we are', 'status' => 'published']);

        $status = $this->state($page);
        $this->assertSame('pending', $status['state']);
        $this->assertSame('https://client.example/who-we-are', $status['url'], 'Where it will be.');
        $this->assertSame('https://client.example/about-us', $status['currentUrl'], 'Where it is now, not the new address.');

        $this->rebuild();
        $this->assertSame('https://client.example/who-we-are', $this->state($page)['currentUrl']);
    }

    /** The build records what was on the site when it started, never more. */
    public function test_a_page_saved_while_a_build_runs_stays_pending_until_the_next_build(): void
    {
        $this->rebuildsConfigured();
        $page = $this->page();
        $this->travel(5)->seconds();

        $this->rebuild(during: fn () => $page->update(['title' => 'Changed mid-build']));

        $this->assertSame('pending', $this->state($page)['state']);
    }

    public function test_a_build_that_fails_for_good_says_so(): void
    {
        $this->rebuildsConfigured();
        $this->rebuild();
        $this->travel(5)->seconds();
        $page = $this->page();

        $this->rebuild(succeeds: false);

        $status = $this->state($page);
        $this->assertSame('failed', $status['state']);
        $this->assertFalse($status['onSite']);

        // The next successful build puts it right.
        $this->travel(5)->seconds();
        $this->rebuild();
        $this->assertSame('live', $this->state($page)['state']);
    }

    public function test_drafts_scheduled_pages_and_servers_without_rebuilds(): void
    {
        $draft = $this->page(['slug' => 'draft', 'status' => 'draft', 'published_at' => null]);
        $scheduled = $this->page(['slug' => 'later', 'status' => 'draft', 'published_at' => null, 'publish_at' => now()->addDay()]);
        $published = $this->page(['slug' => 'now']);

        $this->assertSame('draft', $this->state($draft)['state']);
        $this->assertSame('scheduled', $this->state($scheduled)['state']);
        $this->assertSame('not_configured', $this->state($published)['state'], 'ASTRO_REBUILD_SCRIPT is empty.');

        // Configured, but no build recorded yet (just after this was deployed).
        $this->rebuildsConfigured();
        $this->assertSame('unknown', $this->state($published)['state']);

        // Without PUBLIC_SITE_URL there is an address, but nothing to link to.
        config(['app.public_site_url' => null]);
        $this->assertNull($this->state($published)['url']);
        $this->assertSame('/now', $this->state($published)['path']);
    }

    public function test_the_editor_polls_the_status_endpoint(): void
    {
        $this->rebuildsConfigured();
        $page = $this->page();

        $this->actingAs($this->admin)->getJson(route('pages.site-status', $page))
            ->assertOk()
            ->assertJson(['state' => 'unknown', 'path' => '/about-us', 'url' => 'https://client.example/about-us']);

        // The same rule as the editor itself: an editor can't open another author's draft.
        $draft = $this->page(['slug' => 'secret', 'status' => 'draft', 'published_at' => null]);
        $this->actingAs($this->editor)->getJson(route('pages.site-status', $draft))->assertForbidden();
    }

    // ------------------------------------------------------------------ the menu

    public function test_the_editor_knows_about_the_menu_and_the_home_page(): void
    {
        $page = $this->page();

        $this->actingAs($this->admin)->get(route('pages.edit', $page))->assertInertia(fn ($inertia) => $inertia
            ->where('publicSite.path', '/about-us')
            ->where('menu', ['inMenu' => false, 'full' => false, 'isFrontPage' => false, 'canEdit' => true]));

        NavigationItem::create(['page_id' => $page->id, 'sort_order' => 1]);
        SiteSetting::forceCreate(['id' => SiteSetting::ID, ...SiteSetting::defaults(), 'front_page_id' => $page->id]);

        $this->actingAs($this->editor)->get(route('pages.edit', $page))->assertInertia(fn ($inertia) => $inertia
            ->where('menu', ['inMenu' => true, 'full' => false, 'isFrontPage' => true, 'canEdit' => false]));
    }

    public function test_an_admin_adds_a_published_page_to_the_end_of_the_menu(): void
    {
        $page = $this->page();
        NavigationItem::create(['label' => 'Blog', 'url' => '/blog', 'sort_order' => 4]);

        $this->actingAs($this->admin)->from(route('pages.edit', $page))
            ->post(route('pages.navigation.add', $page))
            ->assertRedirect(route('pages.edit', $page))
            ->assertSessionHas('success', fn ($message) => str_starts_with($message, 'Added "About us" to the end of the menu.'));

        $item = NavigationItem::where('page_id', $page->id)->sole();
        $this->assertSame(5, $item->sort_order);
        $this->assertNull($item->label, 'Follows the page title.');
        // The menu isn't page content: the rebuild is queued explicitly.
        Queue::assertPushed(RebuildAstroJob::class);

        // Again: no second entry.
        $this->actingAs($this->admin)->post(route('pages.navigation.add', $page))
            ->assertSessionHas('success', '"About us" is already in the menu.');
        $this->assertSame(1, NavigationItem::where('page_id', $page->id)->count());
    }

    public function test_drafts_a_full_menu_and_editors_are_refused(): void
    {
        $draft = $this->page(['slug' => 'draft', 'status' => 'draft', 'published_at' => null]);
        $this->actingAs($this->admin)->post(route('pages.navigation.add', $draft))
            ->assertSessionHas('warning', 'Only a published page can be in the menu. Publish it first, then add it.');

        $page = $this->page(['slug' => 'live']);
        Queue::fake(); // forget the rebuild that publishing the page queued
        $this->actingAs($this->editor)->post(route('pages.navigation.add', $page))->assertForbidden();

        foreach (range(1, UpdateSiteSettingsRequest::MAX_NAVIGATION_ITEMS) as $i) {
            NavigationItem::create(['label' => "Link {$i}", 'url' => "/link-{$i}", 'sort_order' => $i]);
        }
        $this->actingAs($this->admin)->post(route('pages.navigation.add', $page))
            ->assertSessionHas('warning', fn ($message) => str_starts_with($message, 'The menu is full'));

        $this->assertSame(0, NavigationItem::whereNotNull('page_id')->count());
        Queue::assertNotPushed(RebuildAstroJob::class);
    }
}
