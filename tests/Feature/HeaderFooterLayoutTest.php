<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\BuildsSiteSettings;
use Tests\TestCase;

/**
 * How the site header and footer are arranged (site_settings.header_layout and
 * footer_layout): the Settings form and the public API. How each one renders
 * is checked by astro/scripts/check-header-footer.mjs.
 */
class HeaderFooterLayoutTest extends TestCase
{
    use BuildsSiteSettings, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create();
    }

    private function save(array $overrides = []): TestResponse
    {
        return $this->actingAs($this->admin)->put('/settings', $this->validSettings($overrides));
    }

    /** [header_layout, footer_layout] from GET /api/public/site. */
    private function publicLayouts(): array
    {
        $json = $this->getJson('/api/public/site')->assertOk()->json();

        return [$json['header_layout'], $json['footer_layout']];
    }

    /** Asserts whether a rebuild was queued, then frees the uniqueness lock as a worker would. */
    private function assertRebuildQueued(bool $queued): void
    {
        $queued ? Queue::assertPushed(RebuildAstroJob::class) : Queue::assertNotPushed(RebuildAstroJob::class);
        Cache::lock(UniqueLock::getKey(new RebuildAstroJob))->forceRelease();
        Queue::fake();
    }

    public function test_both_are_standard_until_another_layout_is_chosen(): void
    {
        $this->assertSame(['standard', 'standard'], $this->publicLayouts());
        $this->assertDatabaseCount('site_settings', 0);

        $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $page) => $page
            ->where('settings.header_layout', 'standard')
            ->where('settings.footer_layout', 'standard')
            ->where('options.headerLayouts', fn ($options) => collect($options)->pluck('value')->all() === ['standard', 'centered-menu', 'centered', 'stacked']
                && collect($options)->every(fn ($option) => filled($option['label']) && filled($option['description'])))
            ->where('options.footerLayouts', fn ($options) => collect($options)->pluck('value')->all() === ['standard', 'columns', 'centered']));

        // Saving the settings without the fields (a Settings page from before them) keeps the default.
        $this->save()->assertSessionHasNoErrors();
        $this->assertSame(['standard', 'standard'], $this->publicLayouts());
    }

    public function test_every_layout_can_be_chosen_and_is_kept(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $this->assertRebuildQueued(true);

        foreach ([['centered', 'columns'], ['stacked', 'centered'], ['centered-menu', 'columns'], ['standard', 'standard'], ['centered', 'centered']] as [$header, $footer]) {
            $this->save(['header_layout' => $header, 'footer_layout' => $footer])->assertSessionHasNoErrors()
                ->assertSessionHas('success', 'Saved. The public site updates in a minute or two.');
            $this->assertSame([$header, $footer], $this->publicLayouts());
            $this->assertRebuildQueued(true);

            $settings = SiteSetting::current();
            $this->assertSame([$header, $footer], [$settings->header_layout, $settings->footer_layout]);
            $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $page) => $page
                ->where('settings.header_layout', $header)
                ->where('settings.footer_layout', $footer));
        }

        // Header and footer are independent, and a save without the fields keeps both.
        $this->save(['footer_layout' => 'columns'])->assertSessionHasNoErrors();
        $this->assertSame(['centered', 'columns'], $this->publicLayouts());
        $this->assertRebuildQueued(true);
        $this->save(['site_name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame(['centered', 'columns'], $this->publicLayouts());
        $this->assertRebuildQueued(true); // the name changed

        // Choosing the layout the site already has changes nothing public: no rebuild.
        $this->save(['site_name' => 'Renamed', 'header_layout' => 'centered'])
            ->assertSessionHas('success', 'Saved. Nothing changed, so the public site stays as it is.');
        $this->assertRebuildQueued(false);
    }

    public function test_a_layout_not_in_the_list_is_rejected(): void
    {
        $this->save(['header_layout' => 'stacked', 'footer_layout' => 'columns']);

        foreach (['split', 'transparent', 'Centered', '', 'constructor', 42, ['centered']] as $invalid) {
            $this->save(['header_layout' => $invalid])->assertSessionHasErrors(['header_layout' => 'Choose one of the header layouts.']);
            $this->save(['footer_layout' => $invalid])->assertSessionHasErrors(['footer_layout' => 'Choose one of the footer layouts.']);
        }
        // A header-only layout isn't a footer one, and the other way round.
        $this->save(['footer_layout' => 'stacked'])->assertSessionHasErrors('footer_layout');
        $this->save(['header_layout' => 'columns'])->assertSessionHasErrors('header_layout');
        // null is "not chosen" only in the database; the form always sends one.
        $this->save(['header_layout' => null])->assertSessionHasErrors('header_layout');

        $this->assertSame(['stacked', 'columns'], $this->publicLayouts(), 'Nothing was saved.');
    }

    public function test_a_row_from_before_the_columns_or_a_value_changed_in_the_database_is_the_default(): void
    {
        $this->save(['header_layout' => 'centered', 'footer_layout' => 'columns']);
        $settings = SiteSetting::current();

        // The existing row after the migration: null.
        $settings->forceFill(['header_layout' => null, 'footer_layout' => null])->saveQuietly();
        $this->assertSame(['standard', 'standard'], $this->publicLayouts());
        $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $page) => $page
            ->where('settings.header_layout', 'standard')
            ->where('settings.footer_layout', 'standard'));

        // Written past validation: never sent, so the build never sees it.
        $settings->forceFill(['header_layout' => 'sideways', 'footer_layout' => 'stacked'])->saveQuietly();
        $this->assertSame(['standard', 'standard'], $this->publicLayouts());
    }

    public function test_editors_cannot_change_them(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->put('/settings', $this->validSettings(['header_layout' => 'centered']))->assertForbidden();
        $this->assertSame(['standard', 'standard'], $this->publicLayouts());
    }
}
