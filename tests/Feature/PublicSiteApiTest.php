<?php

namespace Tests\Feature;

use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\BuildsSiteSettings;
use Tests\TestCase;

/** GET /api/public/site: the settings the Astro build reads. */
class PublicSiteApiTest extends TestCase
{
    use BuildsSiteSettings, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create();
    }

    private function save(array $overrides = []): void
    {
        $this->actingAs($this->admin)->post('/settings', ['_method' => 'PUT', ...$this->validSettings($overrides)])->assertSessionHasNoErrors();
        auth()->logout();
    }

    public function test_without_a_saved_row_it_returns_the_defaults_and_writes_nothing(): void
    {
        $this->getJson('/api/public/site')
            ->assertOk()
            ->assertExactJson([
                'name' => config('app.name'),
                'description' => null,
                'footer_text' => null,
                'show_site_name' => true,
                'theme' => ['brand' => '#1B2A4A', 'fontBody' => 'inter', 'fontHeading' => 'inter', 'corners' => 'soft', 'buttons' => 'solid'],
                'logo' => null,
                'navigation' => [
                    ['label' => 'Home', 'href' => '/'],
                    ['label' => 'Blog', 'href' => '/blog'],
                    ['label' => 'Contact', 'href' => '/contact'],
                ],
                'front_page' => null,
            ]);

        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_the_response_has_exactly_the_documented_shape_and_no_admin_data(): void
    {
        $about = $this->makePage('About');
        $this->save(['navigation' => [...$this->pageEntries($about), ['label' => 'Shop', 'url' => 'https://shop.example.com']], 'front_page_id' => $about->id, 'logo' => $this->image('png', 300, 150)]);

        $json = $this->getJson('/api/public/site')->assertOk()->json();

        $this->assertSame(['name', 'description', 'footer_text', 'show_site_name', 'theme', 'logo', 'navigation', 'front_page'], array_keys($json));
        $this->assertSame(['brand', 'fontBody', 'fontHeading', 'corners', 'buttons'], array_keys($json['theme']));
        $this->assertSame(['url', 'width', 'height'], array_keys($json['logo']));
        $this->assertSame(['slug'], array_keys($json['front_page']));
        foreach ($json['navigation'] as $item) {
            $this->assertSame(['label', 'href'], array_keys($item));
        }
        $this->assertSame([
            'name' => 'Example Studio',
            'description' => 'Websites for small businesses.',
            'footer_text' => 'Storgatan 1, Stockholm',
            'show_site_name' => true,
            'theme' => ['brand' => '#7A1F3D', 'fontBody' => 'serif', 'fontHeading' => 'inter', 'corners' => 'round', 'buttons' => 'outline'],
        ], array_slice($json, 0, 5));

        // No ids, timestamps, emails or other admin data anywhere in it.
        $raw = $this->getJson('/api/public/site')->getContent();
        foreach (['"id"', 'created_at', 'updated_at', 'front_page_id', 'show_in_nav', 'nav_order', 'page_id', 'sort_order', 'author', $this->admin->email, 'status'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function test_the_logo_url_is_absolute_and_points_to_the_conversion(): void
    {
        $this->save(['logo' => $this->image('webp', 900, 300)]);
        $media = SiteSetting::current()->logo();

        $logo = $this->getJson('/api/public/site')->json('logo');

        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/storage/', $logo['url']);
        // On the fake test disk getUrl() is relative ("/storage/..."); the API makes it absolute.
        $this->assertSame(url($media->getUrl('logo')), $logo['url']);
        $this->assertStringContainsString('/conversions/', $logo['url']);
        $this->assertStringEndsWith('.png', $logo['url']);
        $this->assertSame(['width' => 384, 'height' => 128], ['width' => $logo['width'], 'height' => $logo['height']]);
    }

    public function test_navigation_entries_in_any_order_with_their_own_labels(): void
    {
        [$about, $services] = [$this->makePage('About'), $this->makePage('Services')];

        // Blog and Contact are ordinary entries, so they can sit between pages.
        $this->save(['navigation' => [
            ...$this->pageEntries($about),
            self::BLOG,
            ['page_id' => $services->id, 'label' => 'What we do'],
            ['label' => 'Shop', 'url' => 'https://shop.example.com/catalogue'],
            ['label' => 'Get in touch', 'url' => '/contact'],
        ]]);
        $this->assertSame([
            ['label' => 'Home', 'href' => '/'],
            ['label' => 'About', 'href' => '/about'],
            ['label' => 'Blog', 'href' => '/blog'],
            ['label' => 'What we do', 'href' => '/services'],
            ['label' => 'Shop', 'href' => 'https://shop.example.com/catalogue'],
            ['label' => 'Get in touch', 'href' => '/contact'],
        ], $this->getJson('/api/public/site')->json('navigation'));

        // A page entry without a label of its own follows the page's title and slug.
        $about->update(['title' => 'About us', 'slug' => 'about-us']);
        $this->assertSame(['label' => 'About us', 'href' => '/about-us'], $this->getJson('/api/public/site')->json('navigation.1'));

        $this->save(['navigation' => []]);
        $this->assertSame([['label' => 'Home', 'href' => '/']], $this->getJson('/api/public/site')->json('navigation'));
    }

    public function test_a_deleted_page_leaves_the_navigation(): void
    {
        $about = $this->makePage('About');
        $this->save(['navigation' => [...$this->pageEntries($about), self::BLOG]]);

        $about->delete();

        $this->assertSame(['/', '/blog'], array_column($this->getJson('/api/public/site')->json('navigation'), 'href'));
        $this->assertSame(1, NavigationItem::count()); // the foreign key removed its entry
    }

    public function test_drafts_and_scheduled_pages_never_appear(): void
    {
        $about = $this->makePage('About');
        $soon = $this->makePage('Coming soon');
        $this->save(['navigation' => [...$this->pageEntries($about, $soon), self::BLOG], 'front_page_id' => $soon->id]);

        // Unpublished and scheduled after they were chosen: both drop out.
        $about->update(['status' => 'draft']);
        $soon->update(['published_at' => now()->addWeek()]);

        $json = $this->getJson('/api/public/site')->json();
        $this->assertSame(['/', '/blog'], array_column($json['navigation'], 'href'));
        $this->assertNull($json['front_page']);
        $this->assertStringNotContainsString('About', json_encode($json));
        $this->assertStringNotContainsString('soon', json_encode($json));
    }

    public function test_the_front_page_falls_back_when_unpublished_or_deleted(): void
    {
        $welcome = $this->makePage('Welcome');
        $this->save(['front_page_id' => $welcome->id]);
        $this->getJson('/api/public/site')->assertJsonPath('front_page', ['slug' => 'welcome']);

        $welcome->update(['status' => 'draft']);
        $this->getJson('/api/public/site')->assertJsonPath('front_page', null);

        $welcome->update(['status' => 'published']);
        $this->getJson('/api/public/site')->assertJsonPath('front_page', ['slug' => 'welcome']);

        $welcome->delete();
        $this->getJson('/api/public/site')->assertOk()->assertJsonPath('front_page', null);
        $this->assertNull(DB::table('site_settings')->value('front_page_id'));
    }

    public function test_text_comes_back_exactly_as_stored_with_no_markup_added(): void
    {
        $values = [
            'site_name' => '<b>Tom & Jerry</b>',
            'site_description' => '<script>alert(1)</script> "quoted" & \'single\'',
            'footer_text' => '</style><style>body{display:none}</style>',
        ];
        $page = $this->makePage('<em>Page</em> & co');
        $this->save([...$values, 'navigation' => [...$this->pageEntries($page), ['label' => '<b>Shop</b> & "more"', 'url' => '/shop?a=1&b=2']]]);

        $response = $this->getJson('/api/public/site')->assertOk()->assertHeader('Content-Type', 'application/json');
        $json = $response->json();

        // The strings are plain JSON strings, identical to what was typed: not escaped, wrapped or converted.
        $this->assertSame($values['site_name'], $json['name']);
        $this->assertSame($values['site_description'], $json['description']);
        $this->assertSame($values['footer_text'], $json['footer_text']);
        $this->assertSame('<em>Page</em> & co', $json['navigation'][1]['label']);
        $this->assertSame(['label' => '<b>Shop</b> & "more"', 'href' => '/shop?a=1&b=2'], $json['navigation'][2]);
        $this->assertStringNotContainsString('&amp;', $response->getContent());
        $this->assertStringNotContainsString('&lt;', $response->getContent());
    }

    public function test_theme_values_changed_directly_in_the_database_are_replaced_by_the_defaults(): void
    {
        $this->save();
        DB::table('site_settings')->update([
            'brand_color' => '#FFFF00',
            'font_body' => 'comic</style>',
            'font_heading' => 'x',
            'corners' => 'pill',
            'buttons' => 'url(//evil)',
        ]);

        $this->assertSame(
            ['brand' => '#1B2A4A', 'fontBody' => 'inter', 'fontHeading' => 'inter', 'corners' => 'soft', 'buttons' => 'solid'],
            $this->getJson('/api/public/site')->json('theme'),
        );
    }

    public function test_the_query_count_stays_small(): void
    {
        [$a, $b] = [$this->makePage('Alpha'), $this->makePage('Bravo')];
        $this->save(['navigation' => [...$this->pageEntries($a, $b), self::BLOG], 'front_page_id' => $a->id, 'logo' => $this->image('png', 300, 100)]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/public/site')->assertOk();
        $apiQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->actingAs($this->admin)->get('/settings')->assertOk();
        $settingsQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // API: settings row, its media, navigation entries, their pages (one query), front page.
        $this->assertSame(5, $apiQueries);
        // Settings page: the settings row and its media, the pages, the navigation entries, the
        // shared unread count. (actingAs() skips the user lookup a real request makes from the session.)
        $this->assertSame(5, $settingsQueries);
        fwrite(STDERR, "\n[query counts] GET /api/public/site: $apiQueries, GET /settings: $settingsQueries\n");
    }
}
