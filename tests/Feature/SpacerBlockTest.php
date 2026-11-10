<?php

namespace Tests\Feature;

use App\Blocks\Types\SpacerBlock;
use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SpacerBlockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Page $page;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->page = Page::create([
            'author_id' => $this->admin->id, 'title' => 'About', 'slug' => 'about', 'status' => 'published',
            'published_at' => now()->subMinute(), 'content' => '<p>Legacy</p>',
        ]);
    }

    private function save(array ...$spacers): TestResponse
    {
        return $this->actingAs($this->admin)->put("/pages/{$this->page->id}", [
            'title' => 'About',
            'status' => 'published',
            'blocks' => array_map(fn (array $data) => ['type' => 'spacer', 'data' => $data], $spacers),
        ]);
    }

    /** The stored data of each spacer, in order. */
    private function stored(): array
    {
        return array_column($this->page->fresh()->blockList(), 'data');
    }

    public function test_a_spacer_without_a_height_gets_the_default_and_no_colour(): void
    {
        $this->save([], ['height' => null, 'background_color' => null], ['height' => '', 'background_color' => ''])
            ->assertSessionHasNoErrors();

        $default = ['height' => SpacerBlock::HEIGHT_DEFAULT, 'background_color' => null, 'opacity' => SpacerBlock::OPACITY_DEFAULT];
        $this->assertSame([$default, $default, $default], $this->stored());
        $this->assertSame(32, SpacerBlock::HEIGHT_DEFAULT);
        $this->assertSame(100, SpacerBlock::OPACITY_DEFAULT);
    }

    public function test_height_and_colour_are_stored_and_can_be_cleared(): void
    {
        $this->save(['height' => 2, 'background_color' => '#1b2a4a'], ['height' => '240'], ['height' => 120, 'background_color' => '#FFFFFF'], ['height' => 3], ['height' => 15])
            ->assertSessionHasNoErrors();
        $this->assertSame([
            ['height' => 2, 'background_color' => '#1B2A4A', 'opacity' => 100], // the minimum; colour stored in capitals, as a page's colour is
            ['height' => 240, 'background_color' => null, 'opacity' => 100], // the maximum; a form string becomes a number
            ['height' => 120, 'background_color' => '#FFFFFF', 'opacity' => 100],
            ['height' => 3, 'background_color' => null, 'opacity' => 100], // odd heights too: the slider's step of 2 is not a rule
            ['height' => 15, 'background_color' => null, 'opacity' => 100], // below the old 16 px floor
        ], $this->stored());

        // Cleared: back to the default height, and no colour.
        $this->save(['height' => null, 'background_color' => null])->assertSessionHasNoErrors();
        $this->assertSame([['height' => SpacerBlock::HEIGHT_DEFAULT, 'background_color' => null, 'opacity' => 100]], $this->stored());
    }

    public function test_opacity_is_stored_with_a_colour_and_can_be_cleared(): void
    {
        $this->save(
            ['background_color' => '#1B2A4A', 'opacity' => 0], // the minimum
            ['background_color' => '#1B2A4A', 'opacity' => 100], // the maximum
            ['background_color' => '#1B2A4A', 'opacity' => '60'], // a form string becomes a number
            ['background_color' => '#1B2A4A', 'opacity' => 33], // not on the slider's steps of 5, still a whole number
            ['background_color' => '#1B2A4A'], // missing
            ['background_color' => '#1B2A4A', 'opacity' => null], // cleared
            ['background_color' => '#1B2A4A', 'opacity' => ''],
        )->assertSessionHasNoErrors();

        $this->assertSame([0, 100, 60, 33, 100, 100, 100], array_column($this->stored(), 'opacity'));
    }

    public function test_opacity_is_ignored_without_a_colour(): void
    {
        // Nothing to fade, so the default is stored, also when the colour is cleared later.
        $this->save(['opacity' => 40], ['background_color' => null, 'opacity' => 0], ['background_color' => '', 'opacity' => 75])
            ->assertSessionHasNoErrors();
        $this->assertSame([100, 100, 100], array_column($this->stored(), 'opacity'));

        $this->save(['background_color' => '#ABCDEF', 'opacity' => 40])->assertSessionHasNoErrors();
        $this->save(['background_color' => null, 'opacity' => 40])->assertSessionHasNoErrors();
        $this->assertSame([['height' => SpacerBlock::HEIGHT_DEFAULT, 'background_color' => null, 'opacity' => 100]], $this->stored());
    }

    public function test_a_spacer_stored_before_the_opacity_field_is_unchanged(): void
    {
        $old = ['id' => 'old', 'type' => 'spacer', 'data' => ['height' => 48, 'background_color' => '#ABCDEF']];
        $this->page->update(['blocks' => ['version' => 1, 'blocks' => [$old]]]);

        // The API returns it as stored; the site treats the missing opacity as 100 (check-blocks.mjs).
        $this->getJson('/api/public/pages/about')->assertJsonPath('blocks.0.data', $old['data']);

        // Saved again from the editor, it gets the default: still fully opaque.
        $this->save($old['data'])->assertSessionHasNoErrors();
        $this->assertSame([[...$old['data'], 'opacity' => 100]], $this->stored());
    }

    public function test_any_valid_colour_is_accepted_without_a_contrast_check(): void
    {
        // Black and the brand's own colours would fail the page background's contrast rule.
        $this->save(['background_color' => '#000000'], ['background_color' => '#1B2A4A'], ['background_color' => '#FFFF00'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['#000000', '#1B2A4A', '#FFFF00'], array_column($this->stored(), 'background_color'));
    }

    public static function invalidValues(): array
    {
        return [
            'height below the minimum' => [['height' => 1], 'height', 'The spacer height field must be between 2 and 240.'],
            'height zero' => [['height' => 0], 'height', 'The spacer height field must be between 2 and 240.'],
            'height negative' => [['height' => -8], 'height', 'The spacer height field must be between 2 and 240.'],
            'height above the maximum' => [['height' => 241], 'height', 'The spacer height field must be between 2 and 240.'],
            'height not whole' => [['height' => 47.5], 'height', 'The spacer height field must be an integer.'],
            'height text' => [['height' => 'tall'], 'height', 'The spacer height field must be an integer.'],
            // Rejected by "numeric", not by the 2 px floor: "integer" alone takes true as 1.
            'height true' => [['height' => true], 'height', 'The spacer height field must be a number.'],
            'height false' => [['height' => false], 'height', 'The spacer height field must be a number.'],
            'height array' => [['height' => [48]], 'height', null],
            'colour name' => [['background_color' => 'red'], 'background_color', 'Enter the colour as # followed by 6 hexadecimal digits, for example #F5F1EA.'],
            'colour short' => [['background_color' => '#FFF'], 'background_color', null],
            'colour with alpha' => [['background_color' => '#FFFFFF80'], 'background_color', null],
            'colour without #' => [['background_color' => 'FFFFFF'], 'background_color', null],
            'colour with CSS after it' => [['background_color' => '#FFFFFF; position: fixed'], 'background_color', null],
            'colour not hex' => [['background_color' => '#GGGGGG'], 'background_color', null],
            'colour array' => [['background_color' => ['#FFFFFF']], 'background_color', null],
            'opacity below the minimum' => [['background_color' => '#FFFFFF', 'opacity' => -1], 'opacity', 'The spacer opacity field must be between 0 and 100.'],
            'opacity above the maximum' => [['background_color' => '#FFFFFF', 'opacity' => 101], 'opacity', 'The spacer opacity field must be between 0 and 100.'],
            'opacity not whole' => [['background_color' => '#FFFFFF', 'opacity' => 50.5], 'opacity', 'The spacer opacity field must be an integer.'],
            'opacity as a fraction' => [['background_color' => '#FFFFFF', 'opacity' => 0.5], 'opacity', 'The spacer opacity field must be an integer.'],
            'opacity with a percent sign' => [['background_color' => '#FFFFFF', 'opacity' => '60%'], 'opacity', 'The spacer opacity field must be an integer.'],
            'opacity true' => [['background_color' => '#FFFFFF', 'opacity' => true], 'opacity', 'The spacer opacity field must be a number.'],
            'opacity false' => [['background_color' => '#FFFFFF', 'opacity' => false], 'opacity', 'The spacer opacity field must be a number.'],
            'opacity array' => [['background_color' => '#FFFFFF', 'opacity' => [60]], 'opacity', null],
            // Checked even without a colour, so nothing out of range is ever accepted.
            'opacity out of range without a colour' => [['opacity' => 500], 'opacity', 'The spacer opacity field must be between 0 and 100.'],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_are_rejected_and_nothing_is_saved(array $data, string $field, ?string $message): void
    {
        $this->save(['height' => 80], $data)->assertSessionHasErrors(
            $message === null ? ["blocks.1.data.{$field}"] : ["blocks.1.data.{$field}" => $message],
        );

        $this->assertNull($this->page->fresh()->blocks, 'Nothing was saved.');
    }

    public function test_unknown_keys_are_dropped_and_the_api_returns_the_stored_data(): void
    {
        $this->save(['height' => 32, 'background_color' => '#abcdef', 'opacity' => 60, 'width' => 'half', 'html' => '<p>x</p>'])->assertSessionHasNoErrors();

        $this->getJson('/api/public/pages/about')
            ->assertOk()
            ->assertJsonPath('blocks.0.type', 'spacer')
            ->assertJsonPath('blocks.0.data', ['height' => 32, 'background_color' => '#ABCDEF', 'opacity' => 60]);
        // No text, so nothing in the excerpt.
        $this->getJson('/api/public/pages')->assertJsonPath('0.excerpt', '');
    }
}
