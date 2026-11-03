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

/** The site footer's background colour (site_settings.footer_background_color): Settings and the public API. */
class FooterBackgroundColorTest extends TestCase
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
        return $this->getJson('/api/public/site')->assertOk()->json('footer_background_color');
    }

    public function test_the_footer_has_no_colour_unless_one_is_chosen(): void
    {
        $this->assertNull(SiteSetting::current()->footer_background_color);
        $this->assertNull($this->publicColour());
        $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $page) => $page->where('settings.footer_background_color', null));

        $this->save()->assertSessionHasNoErrors();
        $this->assertNull(SiteSetting::current()->footer_background_color);
        $this->assertNull($this->publicColour());
    }

    public function test_the_colour_can_be_set_changed_and_cleared_independently_of_the_header(): void
    {
        $this->save(['footer_background_color' => '#1b2a4a', 'nav_background_color' => '#F5F1EA'])->assertSessionHasNoErrors();
        $this->assertSame('#1B2A4A', SiteSetting::current()->footer_background_color, 'Stored uppercase, like the brand colour.');
        $this->assertSame('#1B2A4A', $this->publicColour());
        $this->assertSame('#F5F1EA', $this->getJson('/api/public/site')->json('nav_background_color'));
        Queue::assertPushed(RebuildAstroJob::class);

        $this->save(['footer_background_color' => '#F5F6F8'])->assertSessionHasNoErrors();
        $this->assertSame('#F5F6F8', $this->publicColour());

        // Kept when the field isn't sent, cleared with null (the header's colour stays).
        $this->save(['site_name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame('#F5F6F8', SiteSetting::current()->footer_background_color);
        $this->save(['footer_background_color' => null])->assertSessionHasNoErrors();
        $this->assertNull($this->publicColour());
        $this->assertSame('#F5F1EA', SiteSetting::current()->nav_background_color);
    }

    public function test_a_colour_neither_text_works_on_is_rejected_naming_the_worst_check_of_each(): void
    {
        $this->save(['footer_background_color' => '#F5F6F8']);

        // A grey the header accepts with dark text is too dark for the footer's muted text.
        $this->save(['nav_background_color' => '#9E9E9E', 'footer_background_color' => '#9E9E9E'])->assertSessionHasErrors([
            'footer_background_color' => 'Neither dark nor white text works on this colour. With dark text, the footer text and the analytics note at 2.3:1 (minimum 4.5:1); with white text, the site name, menu links and footer text at 2.6:1 (minimum 4.5:1). Try a lighter or a darker shade.',
        ])->assertSessionDoesntHaveErrors('nav_background_color');
        foreach (['white', '#fff', 'F5F6F8', 'transparent', 'red;}footer{display:none', 42] as $invalid) {
            $this->save(['footer_background_color' => $invalid])->assertSessionHasErrors([
                'footer_background_color' => 'Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.',
            ]);
        }
        $this->assertSame('#F5F6F8', SiteSetting::current()->footer_background_color, 'Nothing was saved.');
    }

    public function test_the_muted_text_decides_whatever_the_brand_colour(): void
    {
        // Unlike the header, the brand colour never tips the balance here: the footer's muted
        // text needs a lighter colour than the darker brand shade of the links ever does.
        foreach (['#1B2A4A', '#767676'] as $brand) {
            $this->save(['brand_color' => $brand, 'footer_background_color' => '#F5F6F8'])->assertSessionHasNoErrors();
            $this->save(['brand_color' => $brand, 'footer_background_color' => '#C0C0C0'])->assertSessionHasErrors('footer_background_color');
            $this->assertStringStartsWith(
                'Neither dark nor white text works on this colour. With dark text, the footer text and the analytics note at ',
                session('errors')->first('footer_background_color'),
                $brand,
            );
        }
    }

    public function test_the_api_never_sends_a_colour_the_rule_refuses(): void
    {
        $this->save(['footer_background_color' => '#F5F6F8']);
        $settings = SiteSetting::current();

        // Written past validation (in the database, say): values that are no colour, or unreadable.
        foreach (['#9E9E9E', '#C0C0C0', 'red;}footer{display:none', '#fff'] as $stored) {
            $settings->forceFill(['footer_background_color' => $stored])->saveQuietly();
            $this->assertNull($this->publicColour(), $stored);
        }

        // An invalid stored brand means the default brand, for the check too.
        $settings->forceFill(['brand_color' => '#FFFF00', 'footer_background_color' => '#1B2A4A'])->saveQuietly();
        $this->assertSame('#1B2A4A', $this->publicColour());
    }
}
