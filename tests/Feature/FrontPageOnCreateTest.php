<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Make this page the site's home page" on the new page form: suggested for a
 * site's first page, optional after that, admins only. The front page is one
 * column (site_settings.front_page_id), so a new one replaces the old, which
 * stays a normal page at its own address.
 */
class FrontPageOnCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create();
    }

    private function create(string $title, array $data = [], ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->admin)->post('/pages', ['title' => $title, 'status' => 'published', 'blocks' => [], ...$data]);
    }

    /** "/" as the public site resolves it: the front page's slug, or null for the latest posts. */
    private function homeSlug(): ?string
    {
        return $this->getJson('/api/public/site')->assertOk()->json('front_page.slug');
    }

    /** Asserts the rebuilds queued so far, then frees the uniqueness lock as a worker would. */
    private function assertRebuildsQueued(int $count): void
    {
        $count === 0 ? Queue::assertNotPushed(RebuildAstroJob::class) : Queue::assertPushed(RebuildAstroJob::class, $count);
        Cache::lock(UniqueLock::getKey(new RebuildAstroJob))->forceRelease();
        Queue::fake();
    }

    public function test_the_first_page_is_suggested_as_the_front_page_and_later_ones_are_not(): void
    {
        $this->actingAs($this->admin)->get('/pages/create')->assertInertia(fn (Assert $page) => $page
            ->where('frontPage', ['suggest' => true, 'current' => null]));

        $this->create('About', ['make_front_page' => true])->assertSessionHasNoErrors();

        // A page exists now, and it is the front page: later pages get the plain option.
        $this->actingAs($this->admin)->get('/pages/create')->assertInertia(fn (Assert $page) => $page
            ->where('frontPage', ['suggest' => false, 'current' => ['title' => 'About', 'slug' => 'about', 'published' => true]]));
    }

    public function test_creating_the_first_page_with_the_option_makes_it_the_front_page(): void
    {
        $this->assertDatabaseCount('site_settings', 0);

        $this->create('Welcome', ['make_front_page' => true])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Page "Welcome" created. Its address is /welcome. It is now the site\'s home page.');

        $page = Page::where('slug', 'welcome')->sole();
        $this->assertSame($page->id, SiteSetting::current()->front_page_id);
        $this->assertSame('welcome', $this->homeSlug());
        // The settings row is written for the first time, with the defaults the site already used.
        $this->assertSame(config('app.name'), SiteSetting::current()->site_name);
        $this->assertRebuildsQueued(1);
    }

    public function test_setting_a_later_page_as_the_front_page_replaces_the_previous_one(): void
    {
        $this->create('Welcome', ['make_front_page' => true]);
        $this->assertSame('welcome', $this->homeSlug());

        // Without the option, a later page changes nothing.
        $this->create('Contact us');
        $this->assertSame('welcome', $this->homeSlug());

        $this->create('Home 2026', ['make_front_page' => true])->assertSessionHasNoErrors();
        $this->assertSame('home-2026', $this->homeSlug());

        // The previous front page is a normal page again: published, listed, at its own address.
        $this->assertSame('published', Page::where('slug', 'welcome')->sole()->status);
        $this->assertContains('welcome', $this->getJson('/api/public/pages')->json('*.slug'));
        $this->getJson('/api/public/pages/welcome')->assertOk()->assertJsonPath('slug', 'welcome');
    }

    public function test_a_draft_chosen_as_the_front_page_takes_over_when_it_is_published(): void
    {
        $this->create('Welcome', ['make_front_page' => true]);
        $this->assertRebuildsQueued(1);

        $this->create('New home', ['status' => 'draft', 'make_front_page' => true])
            ->assertSessionHas('success', fn (string $message) => str_ends_with($message, 'It is the site\'s home page once it is published; until then the home page shows the latest posts.'));
        $draft = Page::where('slug', 'new-home')->sole();

        // Chosen, but a draft never reaches the public site: "/" shows the latest posts meanwhile.
        $this->assertSame($draft->id, SiteSetting::current()->front_page_id);
        $this->assertNull($this->homeSlug());
        $this->assertRebuildsQueued(1); // "/" changed from Welcome to the latest posts

        // Settings shows it as chosen, and saving the settings keeps it.
        $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $page) => $page
            ->where('settings.front_page_id', $draft->id)
            ->where('pendingFrontPage.title', 'New home'));

        $draft->update(['status' => 'published', 'published_at' => now()]);
        $this->assertSame('new-home', $this->homeSlug());
    }

    public function test_a_draft_front_page_on_a_site_without_one_queues_no_rebuild(): void
    {
        // "/" shows the latest posts before and after: nothing public changed.
        $this->create('Draft home', ['status' => 'draft', 'make_front_page' => true])->assertSessionHasNoErrors();
        $this->assertNotNull(SiteSetting::current()->front_page_id);
        $this->assertRebuildsQueued(0);
    }

    public function test_editors_get_no_option_and_cannot_change_the_front_page(): void
    {
        $editor = User::factory()->editor()->create();
        $this->create('Welcome', ['make_front_page' => true]);

        $this->actingAs($editor)->get('/pages/create')->assertInertia(fn (Assert $page) => $page->where('frontPage', null));
        $this->create('Editor page', ['make_front_page' => true], $editor)->assertSessionHasNoErrors();

        $this->assertSame('welcome', $this->homeSlug());
    }

    public function test_the_option_must_be_a_boolean(): void
    {
        $this->create('About', ['make_front_page' => 'please'])->assertSessionHasErrors('make_front_page');
        $this->assertSame(0, Page::count());
    }
}
