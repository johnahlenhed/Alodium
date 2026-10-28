<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\Feature\Concerns\BuildsSiteSettings;
use Tests\TestCase;

class SiteSettingsTest extends TestCase
{
    use BuildsSiteSettings, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::factory()->create(); // the factory's default role is admin
    }

    private function save(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->put('/settings', $this->validSettings($overrides));
    }

    /** Multipart, as the browser sends a logo: POST with _method=PUT. */
    private function saveWithFile(array $overrides): TestResponse
    {
        return $this->actingAs($this->admin)->post('/settings', ['_method' => 'PUT', ...$this->validSettings($overrides)]);
    }

    /** Asserts the rebuilds queued so far, then frees the uniqueness lock as a worker would. */
    private function assertRebuildsQueued(int $count): void
    {
        $count === 0 ? Queue::assertNotPushed(RebuildAstroJob::class) : Queue::assertPushed(RebuildAstroJob::class, $count);
        Cache::lock(UniqueLock::getKey(new RebuildAstroJob))->forceRelease();
        Queue::fake();
    }

    // --- access ------------------------------------------------------------------

    public function test_guests_are_redirected_to_the_login(): void
    {
        $this->get('/settings')->assertRedirect('/login');
        $this->put('/settings', $this->validSettings())->assertRedirect('/login');
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_editors_get_403_and_cannot_save(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->get('/settings')->assertForbidden();
        $this->actingAs($editor)->put('/settings', $this->validSettings())->assertForbidden();
        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_admins_see_the_defaults_before_anything_is_saved(): void
    {
        $page = $this->makePage('About');
        $this->makePage('Draft', ['status' => 'draft']);

        $this->actingAs($this->admin)->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Settings/Edit')
                ->where('settings.site_name', config('app.name'))
                ->where('settings.brand_color', '#1B2A4A')
                ->where('settings.corners', 'soft')
                // A fresh install: the migration's default Blog and Contact entries.
                ->where('settings.navigation', [
                    ['page_id' => null, 'url' => '/blog', 'label' => 'Blog'],
                    ['page_id' => null, 'url' => '/contact', 'label' => 'Contact'],
                ])
                ->missing('settings.show_blog_in_nav')
                ->where('logo', null)
                ->where('frontPageMissing', false)
                // Only published pages can be chosen.
                ->where('pages', [['id' => $page->id, 'title' => 'About', 'slug' => 'about']])
                ->where('options.minContrast', 4.5)
                ->has('options.fonts', 3));

        // Looking at the page writes nothing.
        $this->assertDatabaseCount('site_settings', 0);
    }

    // --- saving --------------------------------------------------------------------

    public function test_saving_stores_the_settings(): void
    {
        $this->save(['site_name' => '  Example Studio  ', 'brand_color' => '#7a1f3d'])
            ->assertRedirect('/settings')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Saved. The public site updates in a minute or two.');

        $settings = SiteSetting::current();
        $this->assertTrue($settings->exists);
        $this->assertSame(SiteSetting::ID, $settings->id);
        $this->assertSame('Example Studio', $settings->site_name); // trimmed
        $this->assertSame('#7A1F3D', $settings->brand_color); // uppercase
        $this->assertSame('serif', $settings->font_body);
        $this->assertSame(0, NavigationItem::count()); // the form sent an empty navigation

        // A second save updates the same row.
        $this->save(['site_name' => 'Renamed']);
        $this->assertDatabaseCount('site_settings', 1);
        $this->assertSame('Renamed', SiteSetting::current()->site_name);
    }

    public function test_text_is_stored_exactly_as_typed(): void
    {
        $this->save([
            'site_name' => '<b>Tom & Jerry</b>',
            'site_description' => '<script>alert(1)</script> "quoted" & \'single\'',
            'footer_text' => '</style><style>body{display:none}</style>',
        ])->assertSessionHasNoErrors();

        $settings = SiteSetting::current();
        $this->assertSame('<b>Tom & Jerry</b>', $settings->site_name);
        $this->assertSame('<script>alert(1)</script> "quoted" & \'single\'', $settings->site_description);
        $this->assertSame('</style><style>body{display:none}</style>', $settings->footer_text);
    }

    public function test_the_longest_allowed_texts_are_accepted_and_empty_optional_texts_become_null(): void
    {
        $this->save([
            'site_name' => str_repeat('å', 80), // characters, not bytes
            'site_description' => str_repeat('a', 300),
            'footer_text' => str_repeat('a', 200),
        ])->assertSessionHasNoErrors();

        $this->save(['site_description' => '', 'footer_text' => '   '])->assertSessionHasNoErrors();
        $this->assertNull(SiteSetting::current()->site_description);
        $this->assertNull(SiteSetting::current()->footer_text);
    }

    // --- validation ------------------------------------------------------------------

    public function test_each_field_is_validated(): void
    {
        $invalid = [
            'site_name' => [null, '   ', str_repeat('a', 81), "Tab\there", "Line\nbreak", "Nul\0byte", "Bad \xFF UTF-8", ['array']],
            'site_description' => [str_repeat('a', 301), "Two\nlines", "\x07bell"],
            'footer_text' => [str_repeat('a', 201), "Tab\tinside"],
            'show_site_name' => [null, 'yes', 2],
            'brand_color' => [null, '#fff', 'red', '#FFFF00', '#777777', '#1B2A4A; color:red'],
            'font_body' => [null, 'comic', 'Inter', '__proto__', 'constructor'],
            'font_heading' => ['Serif', 'toString'],
            'corners' => [null, 'pill', 'hasOwnProperty'],
            'buttons' => [null, 'ghost', 'solid; x'],
            'navigation' => ['1,2,3', ['x']],
            'front_page_id' => ['abc', 99999],
        ];

        foreach ($invalid as $field => $values) {
            foreach ($values as $value) {
                $key = $field === 'navigation' && is_array($value) ? 'navigation.0' : $field;
                $this->save([$field => $value])->assertSessionHasErrors($key, null, 'default');
            }
        }

        $this->assertDatabaseCount('site_settings', 0);
    }

    public function test_the_contrast_error_is_friendly(): void
    {
        $this->save(['brand_color' => '#FFFF00'])->assertSessionHasErrors([
            'brand_color' => 'This colour is too light: white text on it would be hard to read (contrast 1.0:1, minimum 4.5:1). Try a darker shade.',
        ]);
    }

    public function test_the_front_page_must_be_a_page_on_the_public_site(): void
    {
        $draft = $this->makePage('Draft', ['status' => 'draft']);
        $scheduled = $this->makePage('Later', ['published_at' => now()->addDay()]);
        $reserved = $this->makePage('Blog'); // slug "blog": the public site never builds it
        $about = $this->makePage('About');

        foreach ([$draft, $scheduled, $reserved] as $page) {
            $this->save(['front_page_id' => $page->id])->assertSessionHasErrors(['front_page_id' => 'The front page must be a published page.']);
        }

        $this->save(['front_page_id' => $about->id])->assertSessionHasNoErrors();
        $this->assertSame($about->id, SiteSetting::current()->front_page_id);

        $this->save(['front_page_id' => null])->assertSessionHasNoErrors();
        $this->assertNull(SiteSetting::current()->front_page_id);
    }

    public function test_navigation_is_saved_in_the_order_given(): void
    {
        [$about, $services] = [$this->makePage('About'), $this->makePage('Services')];
        $updatedAt = $services->fresh()->updated_at;

        $this->travel(5)->minutes();
        $this->save(['navigation' => [
            ['page_id' => $about->id, 'label' => ''],
            self::BLOG,
            ['page_id' => $services->id, 'label' => 'What we do'],
            ['label' => 'Shop', 'url' => 'https://shop.example.com/'],
            self::CONTACT,
        ]])->assertSessionHasNoErrors();

        $this->assertSame([
            [1, $about->id, null, null], // empty label: the page's title is shown
            [2, null, '/blog', 'Blog'],
            [3, $services->id, null, 'What we do'],
            [4, null, 'https://shop.example.com/', 'Shop'],
            [5, null, '/contact', 'Contact'],
        ], NavigationItem::orderBy('sort_order')->get()->map(fn ($item) => [$item->sort_order, $item->page_id, $item->url, $item->label])->all());
        // Navigation isn't content: the page's updated_at (dateModified) and revisions stay as they were.
        $this->assertTrue($services->fresh()->updated_at->equalTo($updatedAt));
        $this->assertSame(0, $services->revisions()->count());

        // The edit page gets the list back in the same order.
        $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $p) => $p
            ->where('settings.navigation.2', ['page_id' => $services->id, 'url' => null, 'label' => 'What we do'])
            ->has('settings.navigation', 5));

        // Missing (as from a multipart form with an empty list) means no entries.
        $data = $this->validSettings();
        unset($data['navigation']);
        $this->actingAs($this->admin)->put('/settings', $data)->assertSessionHasNoErrors();
        $this->assertSame(0, NavigationItem::count());
    }

    public function test_navigation_entries_are_validated(): void
    {
        $pages = collect(range(1, 15))->map(fn ($i) => $this->makePage("Page $i"));
        $draft = $this->makePage('Draft', ['status' => 'draft']);
        $reserved = $this->makePage('Contact'); // slug "contact": the public site never builds it
        $this->save(['navigation' => [self::BLOG]])->assertSessionHasNoErrors();

        $rejected = [
            'navigation' => [$this->pageEntries(...$pages->all()), 'The navigation can hold at most 14 links after Home.'],
            'navigation.0.page_id' => [[['page_id' => $draft->id]], 'Only published pages can be in the navigation.'],
            'navigation.1.page_id' => [$this->pageEntries($pages[0], $pages[0]), 'A page can only be in the navigation once.'],
        ];
        foreach ($rejected as $key => [$navigation, $message]) {
            $this->save(['navigation' => $navigation])->assertSessionHasErrors([$key => $message]);
        }

        $invalidEntries = [
            'navigation.0.page_id' => [
                ['page_id' => $reserved->id], ['page_id' => 99999], ['page_id' => 'abc'],
                [], ['label' => 'Nothing'], // neither a page nor a URL
                ['page_id' => $pages[0]->id, 'url' => '/blog'], // both
            ],
            'navigation.0.label' => [
                ['url' => '/blog'], ['url' => '/blog', 'label' => '   '], // a URL needs a label
                ['url' => '/blog', 'label' => str_repeat('a', 61)], ['url' => '/blog', 'label' => "Two\nlines"],
                ['page_id' => $pages[0]->id, 'label' => "Tab\there"],
            ],
            'navigation.0.url' => [
                ['label' => 'x', 'url' => 'javascript:alert(1)'], ['label' => 'x', 'url' => '//evil.example'],
                ['label' => 'x', 'url' => 'about'], ['label' => 'x', 'url' => 'mailto:a@example.com'],
                ['label' => 'x', 'url' => 'ftp://example.com'], ['label' => 'x', 'url' => '/a b'],
                ['label' => 'x', 'url' => 'https://'.str_repeat('a', 2040).'.com'],
            ],
        ];
        foreach ($invalidEntries as $key => $entries) {
            foreach ($entries as $entry) {
                $this->save(['navigation' => [$entry]])->assertSessionHasErrors($key);
            }
        }

        // A failed save changes nothing: still the entry from before.
        $this->assertSame(['/blog'], NavigationItem::pluck('url')->all());

        // The limit itself is allowed: 12 pages plus Blog and Contact, as before.
        $this->save(['navigation' => [...$this->pageEntries(...$pages->take(12)->all()), self::BLOG, self::CONTACT]])->assertSessionHasNoErrors();
        $this->assertSame(14, NavigationItem::count());
    }

    // --- logo --------------------------------------------------------------------------

    public function test_png_jpeg_and_webp_logos_are_accepted_through_a_spoofed_multipart_put(): void
    {
        foreach (['png', 'jpeg', 'webp'] as $type) {
            $this->saveWithFile(['logo' => $this->image($type, 600, 300)])
                ->assertRedirect('/settings')
                ->assertSessionHasNoErrors();

            $logo = SiteSetting::current()->logo();
            $this->assertNotNull($logo, $type);
            $this->assertSame(['image/png', 'image/jpeg', 'image/webp'][array_search($type, ['png', 'jpeg', 'webp'])], $logo->mime_type);
            $this->assertTrue($logo->hasGeneratedConversion('logo'));
        }

        // singleFile(): each upload replaced the previous logo.
        $this->assertSame(1, SiteSetting::current()->getMedia('logo')->count());
    }

    public function test_the_logo_conversion_is_at_most_128px_high_and_keeps_transparency(): void
    {
        $this->saveWithFile(['logo' => $this->image('png', 600, 300)])->assertSessionHasNoErrors();
        $logo = SiteSetting::current()->logo();

        $path = $logo->getPath('logo');
        $this->assertSame([256, 128], array_slice(getimagesize($path), 0, 2));
        $this->assertSame(256, $logo->getCustomProperty('logo_width'));
        $this->assertSame(128, $logo->getCustomProperty('logo_height'));

        // The corner was transparent in the upload and still is (alpha 127 = fully transparent).
        $converted = imagecreatefrompng($path);
        $this->assertSame(127, (imagecolorat($converted, 0, 0) >> 24) & 0x7F);
        $this->assertSame(0, (imagecolorat($converted, 128, 64) >> 24) & 0x7F);

        // A small logo is not enlarged.
        $this->saveWithFile(['logo' => $this->image('png', 80, 64)])->assertSessionHasNoErrors();
        $this->assertSame([80, 64], array_slice(getimagesize(SiteSetting::current()->logo()->getPath('logo')), 0, 2));
    }

    public function test_unsafe_or_unsuitable_logos_are_rejected(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200"><script>alert(1)</script><rect width="200" height="200"/></svg>';

        $cases = [
            'SVG' => [$this->fileWithContent('logo.svg', $svg), 'The logo must be a PNG, JPEG or WebP image. SVG and GIF are not supported.'],
            'SVG renamed to .png' => [$this->fileWithContent('logo.png', $svg), 'The logo must be a PNG, JPEG or WebP image. SVG and GIF are not supported.'],
            'GIF' => [$this->image('gif', 200, 200), 'The logo must be a PNG, JPEG or WebP image. SVG and GIF are not supported.'],
            'GIF renamed to .png' => [$this->image('gif', 200, 200, 'logo.png'), 'The logo must be a PNG, JPEG or WebP image. SVG and GIF are not supported.'],
            'PHP renamed to .png' => [$this->fileWithContent('logo.png', '<?php echo shell_exec($_GET["c"]); ?>'), 'The logo must be a PNG, JPEG or WebP image. SVG and GIF are not supported.'],
            'over 2 MB' => [$this->image('png', 200, 200, padTo: 2 * 1024 * 1024 + 1), 'The logo can be at most 2 MB.'],
            'too small' => [$this->image('png', 63, 200), 'The logo must be between 64 and 4000 pixels wide and high.'],
            'too large' => [$this->image('png', 4001, 64), 'The logo must be between 64 and 4000 pixels wide and high.'],
        ];

        foreach ($cases as $label => [$file, $message]) {
            $this->saveWithFile(['logo' => $file])->assertSessionHasErrors(['logo' => $message], null, 'default');
            $this->assertSame(0, Media::count(), $label);
        }

        // Exactly 2 MB and exactly 64 px are fine.
        $this->saveWithFile(['logo' => $this->image('png', 64, 64, padTo: 2 * 1024 * 1024)])->assertSessionHasNoErrors();
    }

    public function test_the_logo_can_be_removed(): void
    {
        $this->saveWithFile(['logo' => $this->image('png', 300, 100)]);
        $this->assertNotNull(SiteSetting::current()->logo());

        $this->save(['remove_logo' => true])->assertSessionHasNoErrors();
        $this->assertNull(SiteSetting::current()->logo());
        $this->assertSame(0, Media::count());
    }

    public function test_the_logo_is_not_in_the_media_library(): void
    {
        $this->saveWithFile(['logo' => $this->image('png', 300, 100)]);

        $this->actingAs($this->admin)->getJson('/api/media')->assertOk()->assertJsonCount(0);
        $this->actingAs($this->admin)->get('/media')->assertInertia(fn (Assert $p) => $p->has('media', 0));
    }

    // --- rebuilds --------------------------------------------------------------------------

    public function test_a_rebuild_is_queued_only_when_something_visible_changed(): void
    {
        Queue::fake();
        $about = $this->makePage('About');
        $this->assertRebuildsQueued(1); // publishing a page (the existing observer)

        // Saving the defaults the site already uses changes nothing.
        $defaults = SiteSetting::defaults();
        $this->save([...array_intersect_key($defaults, $this->validSettings()), 'navigation' => [self::BLOG, self::CONTACT]])
            ->assertSessionHas('success', 'Saved. Nothing changed, so the public site stays as it is.');
        $this->assertRebuildsQueued(0);

        $this->save()->assertSessionHas('success', 'Saved. The public site updates in a minute or two.');
        $this->assertRebuildsQueued(1);

        $this->save(); // unchanged
        $this->assertRebuildsQueued(0);

        $this->save(['brand_color' => '#7a1f3d']); // same colour, other case
        $this->assertRebuildsQueued(0);

        $this->save(['site_description' => 'Changed']);
        $this->assertRebuildsQueued(1);

        $this->save(['site_description' => 'Changed', 'navigation' => $this->pageEntries($about)]);
        $this->assertRebuildsQueued(1);

        $this->save(['site_description' => 'Changed', 'navigation' => $this->pageEntries($about)]); // the same list again
        $this->assertRebuildsQueued(0);

        $this->save(['site_description' => 'Changed', 'navigation' => [['page_id' => $about->id, 'label' => 'About us']]]); // a new label
        $this->assertRebuildsQueued(1);

        $this->save(['site_description' => 'Changed', 'navigation' => $this->pageEntries($about), 'front_page_id' => $about->id]);
        $this->assertRebuildsQueued(1);

        $this->save(['site_description' => 'Changed', 'brand_color' => '#FFFF00']); // invalid
        $this->assertRebuildsQueued(0);

        $base = ['site_description' => 'Changed', 'navigation' => $this->pageEntries($about), 'front_page_id' => $about->id];
        $this->saveWithFile([...$base, 'logo' => $this->image('png', 300, 100)]);
        $this->assertRebuildsQueued(1);

        $this->save([...$base, 'remove_logo' => true]);
        $this->assertRebuildsQueued(1);

        $this->save([...$base, 'remove_logo' => true]); // no logo left to remove
        $this->assertRebuildsQueued(0);
    }

    public function test_changing_a_navigation_or_front_page_page_queues_a_rebuild(): void
    {
        Queue::fake();
        $inNav = $this->makePage('About');
        $front = $this->makePage('Welcome');
        $this->save(['navigation' => $this->pageEntries($inNav), 'front_page_id' => $front->id]);
        $this->assertRebuildsQueued(1);

        $inNav->update(['title' => 'About us']);
        $this->assertRebuildsQueued(1);

        $inNav->update(['slug' => 'about-us']);
        $this->assertRebuildsQueued(1);

        $front->update(['title' => 'Hello']);
        $this->assertRebuildsQueued(1);

        $inNav->update(['status' => 'draft']); // unpublished: leaves the navigation
        $this->assertRebuildsQueued(1);

        $front->update(['status' => 'draft']); // unpublished: the home page shows the latest posts again
        $this->assertRebuildsQueued(1);
        $this->getJson('/api/public/site')->assertJsonPath('front_page', null)->assertJsonCount(1, 'navigation'); // Home only

        // A draft that is in neither changes nothing public.
        $draft = $this->makePage('Notes', ['status' => 'draft']);
        $draft->update(['title' => 'More notes']);
        $this->assertRebuildsQueued(0);
    }

    public function test_an_unpublished_or_deleted_front_page_breaks_nothing(): void
    {
        Queue::fake();
        $front = $this->makePage('Welcome');
        $this->save(['front_page_id' => $front->id]);
        $this->assertRebuildsQueued(1);

        $front->update(['status' => 'draft']);
        $this->assertRebuildsQueued(1);
        $this->actingAs($this->admin)->get('/settings')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('settings.front_page_id', null)->where('frontPageMissing', true));
        // The form then sends no front page, which saves fine and changes nothing public.
        $this->save(['front_page_id' => null])->assertSessionHasNoErrors();
        $this->assertRebuildsQueued(0);

        $other = $this->makePage('Other');
        $this->save(['front_page_id' => $other->id]);
        $this->assertRebuildsQueued(1);

        $other->delete(); // published page deleted: the observer queues a rebuild
        $this->assertRebuildsQueued(1);
        $this->assertNull(SiteSetting::current()->front_page_id); // nullOnDelete
        $this->getJson('/api/public/site')->assertOk()->assertJsonPath('front_page', null);
        $this->actingAs($this->admin)->get('/settings')->assertOk()->assertInertia(fn (Assert $p) => $p->where('frontPageMissing', false));
    }
}
