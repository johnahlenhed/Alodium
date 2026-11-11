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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\BuildsSiteSettings;
use Tests\TestCase;

/**
 * The header's padding above and below its content (site_settings.header_padding):
 * the Settings form and the public API. How it renders, and that the header stays
 * taller than the logo at every setting and width, is checked by
 * astro/scripts/check-header-footer.mjs.
 */
class HeaderPaddingTest extends TestCase
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

    private function publicPadding(): mixed
    {
        return $this->getJson('/api/public/site')->assertOk()->json('header_padding');
    }

    private function adminPadding(): mixed
    {
        $padding = null;
        $this->actingAs($this->admin)->get('/settings')->assertInertia(function (Assert $page) use (&$padding) {
            $padding = $page->toArray()['props']['settings']['header_padding'];
        });

        return $padding;
    }

    /** Asserts whether a rebuild was queued, then frees the uniqueness lock as a worker would. */
    private function assertRebuildQueued(bool $queued): void
    {
        $queued ? Queue::assertPushed(RebuildAstroJob::class) : Queue::assertNotPushed(RebuildAstroJob::class);
        Cache::lock(UniqueLock::getKey(new RebuildAstroJob))->forceRelease();
        Queue::fake();
    }

    public function test_the_default_is_the_padding_the_header_always_had(): void
    {
        // No saved row: the default, and nothing is written.
        $this->assertSame(12, $this->publicPadding());
        $this->assertDatabaseCount('site_settings', 0);
        $this->assertSame(12, SiteSetting::defaults()['header_padding']);

        $this->actingAs($this->admin)->get('/settings')->assertInertia(fn (Assert $page) => $page
            ->where('settings.header_padding', 12)
            ->where('options.headerPadding', ['min' => 8, 'max' => 48, 'default' => 12, 'step' => 4]));

        // Saving without the field (a Settings page from before it) keeps the default; the
        // first save writes the defaults' row, as for the logo height.
        $this->save()->assertSessionHasNoErrors();
        $this->assertSame(12, SiteSetting::current()->header_padding);
        $this->assertSame(12, $this->publicPadding());
    }

    public function test_a_row_from_before_the_column_or_a_value_changed_in_the_database_shows_within_the_bounds(): void
    {
        $this->save(['header_padding' => 32]);
        $settings = SiteSetting::current();

        // The existing row after the migration: null, the default.
        $settings->forceFill(['header_padding' => null])->saveQuietly();
        $this->assertSame([12, 12], [$this->publicPadding(), $this->adminPadding()]);

        // Written past validation: clamped, so the build never sees a value out of range.
        $settings->forceFill(['header_padding' => 500])->saveQuietly();
        $this->assertSame([48, 48], [$this->publicPadding(), $this->adminPadding()]);
        $settings->forceFill(['header_padding' => 0])->saveQuietly();
        $this->assertSame([8, 8], [$this->publicPadding(), $this->adminPadding()]);
    }

    public function test_it_can_be_set_and_cleared(): void
    {
        $this->save()->assertSessionHasNoErrors();
        $this->assertRebuildQueued(true);

        // Both ends, the default, and a value off the slider's steps of 4 (the step is not a rule).
        foreach ([8, 48, 12, 30, '20'] as $padding) {
            $this->save(['header_padding' => $padding])->assertSessionHasNoErrors()
                ->assertSessionHas('success', 'Saved. The public site updates in a minute or two.');
            $this->assertSame((int) $padding, SiteSetting::current()->header_padding);
            $this->assertSame([(int) $padding, (int) $padding], [$this->publicPadding(), $this->adminPadding()]);
            $this->assertRebuildQueued(true);
        }

        // A save without the field keeps it; the same value again changes nothing public.
        $this->save(['site_name' => 'Renamed'])->assertSessionHasNoErrors();
        $this->assertSame(20, $this->publicPadding());
        $this->assertRebuildQueued(true); // the name changed
        $this->save(['site_name' => 'Renamed', 'header_padding' => 20])
            ->assertSessionHas('success', 'Saved. Nothing changed, so the public site stays as it is.');
        $this->assertRebuildQueued(false);

        // Cleared: stored as null, shown as the default.
        $this->save(['site_name' => 'Renamed', 'header_padding' => null])->assertSessionHasNoErrors();
        $this->assertNull(SiteSetting::current()->header_padding);
        $this->assertSame([12, 12], [$this->publicPadding(), $this->adminPadding()]);
        $this->assertRebuildQueued(true);
    }

    public function test_the_padding_and_the_logo_height_are_independent(): void
    {
        $this->save(['logo_height' => 96, 'header_padding' => 8])->assertSessionHasNoErrors();
        $this->save(['header_padding' => 48])->assertSessionHasNoErrors();
        $this->assertSame([96, 48], [SiteSetting::current()->logo_height, SiteSetting::current()->header_padding]);

        $this->save(['logo_height' => 24])->assertSessionHasNoErrors();
        $this->assertSame([24, 48], [SiteSetting::current()->logo_height, SiteSetting::current()->header_padding]);
    }

    public static function invalidPaddings(): array
    {
        $range = 'The header padding must be between 8 and 48 pixels.';
        $whole = 'The header padding must be a whole number of pixels.';

        return [
            'below the minimum' => [7, $range],
            'zero' => [0, $range],
            'negative' => [-12, $range],
            'above the maximum' => [49, $range],
            'far above' => [500, $range],
            'not whole' => [12.5, $whole],
            'with a unit' => ['12px', $whole],
            'in rem' => ['0.75rem', $whole],
            'text' => ['large', $whole],
            // Rejected by "numeric": "integer" alone takes true as 1 (and the floor would not catch it).
            'true' => [true, $whole],
            'false' => [false, $whole],
            'a list' => [[12], $whole],
        ];
    }

    #[DataProvider('invalidPaddings')]
    public function test_a_padding_outside_the_bounds_is_rejected_and_nothing_is_saved(mixed $padding, string $message): void
    {
        $this->save(['header_padding' => 32])->assertSessionHasNoErrors();

        $this->save(['site_name' => 'Renamed', 'header_padding' => $padding])->assertSessionHasErrors(['header_padding' => $message]);

        $this->assertSame(32, SiteSetting::current()->header_padding, 'Nothing was saved.');
        $this->assertSame('Example Studio', SiteSetting::current()->site_name);
    }

    public function test_editors_cannot_change_it(): void
    {
        $editor = User::factory()->editor()->create();

        $this->actingAs($editor)->put('/settings', $this->validSettings(['header_padding' => 40]))->assertForbidden();
        $this->assertSame(12, $this->publicPadding());
    }
}
