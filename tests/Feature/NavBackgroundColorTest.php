<?php

namespace Tests\Feature;

use App\Jobs\RebuildAstroJob;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\BuildsSiteSettings;
use Tests\TestCase;

/** The site header's background colour (site_settings.nav_background_color): Settings and the public API. */
class NavBackgroundColorTest extends TestCase
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

    private function publicColour(): mixed
    {
        return $this->getJson('/api/public/site')->assertOk()->json('nav_background_color');
    }

    public function test_the_header_has_no_colour_unless_one_is_chosen(): void
    {
        $this->assertNull(SiteSetting::current()->nav_background_color);
        $this->assertNull($this->publicColour());
        $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('settings.nav_background_color', null));

        // Saving the settings without the field (a Settings page from before it) keeps none.
        $this->save()->assertSessionHasNoErrors();
        $this->assertNull(SiteSetting::current()->nav_background_color);
        $this->assertNull($this->publicColour());
    }

    public function test_the_colour_can_be_set_changed_and_cleared(): void
    {
        // A dark colour (white text) and a light one (dark text) are both accepted.
        $this->save(['nav_background_color' => '#1b2a4a'])->assertSessionHasNoErrors();
        $this->assertSame('#1B2A4A', SiteSetting::current()->nav_background_color, 'Stored uppercase, like the brand colour.');
        $this->assertSame('#1B2A4A', $this->publicColour());
        Queue::assertPushed(RebuildAstroJob::class);

        $this->save(['nav_background_color' => '#F5F1EA'])->assertSessionHasNoErrors();
        $this->assertSame('#F5F1EA', $this->publicColour());

        // Kept when the field isn't sent, cleared with null.
        $this->save(['site_name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame('#F5F1EA', SiteSetting::current()->nav_background_color);
        $this->save(['nav_background_color' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->publicColour());
    }

    public function test_a_colour_neither_text_works_on_is_rejected_naming_the_worst_check_of_each(): void
    {
        $this->save(['nav_background_color' => '#F5F1EA']);

        // Mid grey with a light brand colour, in the same save.
        $this->save(['brand_color' => '#767676', 'nav_background_color' => '#888888'])->assertSessionHasErrors([
            'nav_background_color' => 'Neither dark nor white text works on this colour. With dark text, the current page and hovered links at 1.9:1 (minimum 4.5:1); with white text, the site name and menu links at 3.5:1 (minimum 4.5:1). Try a lighter or a darker shade.',
        ]);
        foreach (['white', '#fff', '1B2A4A', 'transparent', 'red;}header{display:none', 42] as $invalid) {
            $this->save(['nav_background_color' => $invalid])->assertSessionHasErrors([
                'nav_background_color' => 'Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.',
            ]);
        }
        $this->assertSame('#F5F1EA', SiteSetting::current()->nav_background_color, 'Nothing was saved.');
    }

    public function test_it_is_checked_with_the_brand_colour_from_the_same_save(): void
    {
        // #888888 works with dark text when the links' darker brand shade is dark enough.
        $this->save(['brand_color' => '#1B2A4A', 'nav_background_color' => '#888888'])->assertSessionHasNoErrors();
        $this->assertSame('#888888', $this->publicColour());

        // Changing to a light brand in the same save that keeps the colour is refused.
        $this->save(['brand_color' => '#767676', 'nav_background_color' => '#888888'])->assertSessionHasErrors('nav_background_color');
        $this->assertSame('#1B2A4A', SiteSetting::current()->brand_color, 'Nothing was saved.');
    }

    public function test_the_api_never_sends_a_colour_the_rule_refuses(): void
    {
        $this->save(['brand_color' => '#1B2A4A', 'nav_background_color' => '#888888']);
        $settings = SiteSetting::current();

        // A brand change written past validation (in the database, say): the grey no longer works.
        $settings->forceFill(['brand_color' => '#767676'])->saveQuietly();
        $this->assertNull($this->publicColour());

        // Values that are no colour, or unreadable, are never sent.
        foreach (['#808080', 'red;}header{display:none', '#fff'] as $stored) {
            $settings->forceFill(['brand_color' => '#1B2A4A', 'nav_background_color' => $stored])->saveQuietly();
            $this->assertNull($this->publicColour(), $stored);
        }

        // An invalid stored brand means the default brand, for the check too.
        $settings->forceFill(['brand_color' => '#FFFF00', 'nav_background_color' => '#888888'])->saveQuietly();
        $this->assertSame('#888888', $this->publicColour());
    }
}
