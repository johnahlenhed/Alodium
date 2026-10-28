<?php

namespace Tests\Feature;

use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\PublicSiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The navigation_items migration must carry today's navigation over unchanged.
 * Each test sets up the old columns (pages.show_in_nav / nav_order and the Blog
 * and Contact switches), runs the real migration again, and compares the public
 * navigation with what the code before it produced (oldNavigation(), a copy).
 */
class NavigationBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_28_100000_create_navigation_items_table.php';

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = User::factory()->create();
    }

    /** PublicSiteSettings::navigation() before navigation_items, unchanged. */
    private function oldNavigation(): array
    {
        $settings = SiteSetting::current();
        $pages = Page::onPublicSite()
            ->where('show_in_nav', true)
            ->orderBy('nav_order')
            ->orderBy('id')
            ->get(['title', 'slug'])
            ->map(fn (Page $page) => ['label' => $page->title, 'href' => '/'.$page->slug])
            ->all();

        return [
            ['label' => 'Home', 'href' => '/'],
            ...$pages,
            ...($settings->show_blog_in_nav ? [['label' => 'Blog', 'href' => '/blog']] : []),
            ...($settings->show_contact_in_nav ? [['label' => 'Contact', 'href' => '/contact']] : []),
        ];
    }

    private function runMigrationAgain(): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $migration->up();
    }

    private function newNavigation(): array
    {
        return app(PublicSiteSettings::class)->payload()['navigation'];
    }

    /** A page with the old navigation columns set directly, as the old Settings page stored them. */
    private function page(string $slug, bool $inNav, int $order = 0, array $attributes = []): Page
    {
        $page = Page::create([
            'author_id' => $this->author->id,
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'content' => '<p>x</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
            ...$attributes,
        ]);
        Page::query()->whereKey($page->id)->toBase()->update(['show_in_nav' => $inNav, 'nav_order' => $inNav ? $order : 0]);

        return $page;
    }

    private function saveSwitches(bool $blog, bool $contact): void
    {
        $settings = SiteSetting::current();
        $settings->forceFill(['show_blog_in_nav' => $blog, 'show_contact_in_nav' => $contact])->save();
    }

    public static function switches(): array
    {
        return [
            'Blog and Contact' => [true, true],
            'Blog only' => [true, false],
            'Contact only' => [false, true],
            'neither' => [false, false],
        ];
    }

    #[DataProvider('switches')]
    public function test_pages_and_switches_are_carried_over_in_the_same_order(bool $blog, bool $contact): void
    {
        $this->saveSwitches($blog, $contact);
        $this->page('services', true, 2);
        $this->page('about', true, 1);
        $this->page('team', true, 2); // same nav_order as services: the older page (lower id) first
        $this->page('history', false);
        $this->page('<em>odd</em>-title', true, 3, ['title' => '<em>Odd</em> & co']); // text stays as stored
        $expected = $this->oldNavigation();

        $this->runMigrationAgain();

        $this->assertSame($expected, $this->newNavigation());
        // Page entries keep no label of their own, so a renamed page still shows its new title.
        $this->assertSame([null, null, null, null], NavigationItem::whereNotNull('page_id')->pluck('label')->all());
        $this->assertSame(range(1, NavigationItem::count()), NavigationItem::orderBy('sort_order')->pluck('sort_order')->all());
    }

    public function test_without_a_saved_settings_row_the_default_blog_and_contact_are_carried_over(): void
    {
        $this->assertNull(SiteSetting::query()->find(SiteSetting::ID));
        $this->page('about', true, 1);
        $expected = $this->oldNavigation();

        $this->runMigrationAgain();

        $this->assertSame(['/', '/about', '/blog', '/contact'], array_column($expected, 'href'));
        $this->assertSame($expected, $this->newNavigation());
    }

    public function test_flagged_pages_off_the_public_site_stay_hidden_and_come_back_in_place(): void
    {
        $this->saveSwitches(true, false);
        $this->page('first', true, 1);
        $draft = $this->page('draft-page', true, 2, ['status' => 'draft', 'published_at' => null]);
        $scheduled = $this->page('later', true, 3, ['published_at' => now()->addWeek()]);
        $this->page('last', true, 4);
        $expected = $this->oldNavigation();

        $this->runMigrationAgain();

        $this->assertSame(['/', '/first', '/last', '/blog'], array_column($this->newNavigation(), 'href'));
        $this->assertSame($expected, $this->newNavigation());

        // Published later: in the position the old flags gave them, as the old code would show it.
        Page::query()->whereKey([$draft->id, $scheduled->id])->toBase()->update(['status' => 'published', 'published_at' => now()->subMinute()]);
        $this->assertSame($this->oldNavigation(), $this->newNavigation());
        $this->assertSame(['/', '/first', '/draft-page', '/later', '/last', '/blog'], array_column($this->newNavigation(), 'href'));
    }

    public function test_random_configurations_match_the_old_navigation(): void
    {
        mt_srand(20261028);
        for ($round = 0; $round < 40; $round++) {
            Page::query()->toBase()->delete();
            $this->saveSwitches((bool) mt_rand(0, 1), (bool) mt_rand(0, 1));
            foreach (range(1, mt_rand(0, 8)) as $i) {
                $status = mt_rand(0, 4) === 0 ? 'draft' : 'published';
                $this->page("page-{$round}-{$i}", (bool) mt_rand(0, 1), mt_rand(0, 4), [
                    'status' => $status,
                    'published_at' => $status === 'published' ? now()->subDays(mt_rand(-2, 5)) : null,
                ]);
            }
            $expected = $this->oldNavigation();

            $this->runMigrationAgain();

            $this->assertSame($expected, $this->newNavigation(), "round {$round}");
        }
    }

    public function test_rolling_back_leaves_the_old_columns_as_they_were(): void
    {
        $this->saveSwitches(false, true);
        $about = $this->page('about', true, 5);

        $migration = require base_path(self::MIGRATION);
        $migration->down();

        $this->assertFalse(Schema::hasTable('navigation_items'));
        $this->assertSame([1, 5], array_values((array) DB::table('pages')->where('id', $about->id)->first(['show_in_nav', 'nav_order'])));
        $this->assertSame([0, 1], array_values((array) DB::table('site_settings')->first(['show_blog_in_nav', 'show_contact_in_nav'])));
        $migration->up();
    }
}
