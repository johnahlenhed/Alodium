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

        $default = ['height' => SpacerBlock::HEIGHT_DEFAULT, 'background_color' => null];
        $this->assertSame([$default, $default, $default], $this->stored());
        $this->assertSame(32, SpacerBlock::HEIGHT_DEFAULT);
    }

    public function test_height_and_colour_are_stored_and_can_be_cleared(): void
    {
        $this->save(['height' => 2, 'background_color' => '#1b2a4a'], ['height' => '240'], ['height' => 120, 'background_color' => '#FFFFFF'], ['height' => 3], ['height' => 15])
            ->assertSessionHasNoErrors();
        $this->assertSame([
            ['height' => 2, 'background_color' => '#1B2A4A'], // the minimum; colour stored in capitals, as a page's colour is
            ['height' => 240, 'background_color' => null], // the maximum; a form string becomes a number
            ['height' => 120, 'background_color' => '#FFFFFF'],
            ['height' => 3, 'background_color' => null], // odd heights too: the slider's step of 2 is not a rule
            ['height' => 15, 'background_color' => null], // below the old 16 px floor
        ], $this->stored());

        // Cleared: back to the default height, and no colour.
        $this->save(['height' => null, 'background_color' => null])->assertSessionHasNoErrors();
        $this->assertSame([['height' => SpacerBlock::HEIGHT_DEFAULT, 'background_color' => null]], $this->stored());
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
            'height true' => [['height' => true], 'height', null],
            'height array' => [['height' => [48]], 'height', null],
            'colour name' => [['background_color' => 'red'], 'background_color', 'Enter the colour as # followed by 6 hexadecimal digits, for example #F5F1EA.'],
            'colour short' => [['background_color' => '#FFF'], 'background_color', null],
            'colour with alpha' => [['background_color' => '#FFFFFF80'], 'background_color', null],
            'colour without #' => [['background_color' => 'FFFFFF'], 'background_color', null],
            'colour with CSS after it' => [['background_color' => '#FFFFFF; position: fixed'], 'background_color', null],
            'colour not hex' => [['background_color' => '#GGGGGG'], 'background_color', null],
            'colour array' => [['background_color' => ['#FFFFFF']], 'background_color', null],
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
        $this->save(['height' => 32, 'background_color' => '#abcdef', 'width' => 'half', 'html' => '<p>x</p>'])->assertSessionHasNoErrors();

        $this->getJson('/api/public/pages/about')
            ->assertOk()
            ->assertJsonPath('blocks.0.type', 'spacer')
            ->assertJsonPath('blocks.0.data', ['height' => 32, 'background_color' => '#ABCDEF']);
        // No text, so nothing in the excerpt.
        $this->getJson('/api/public/pages')->assertJsonPath('0.excerpt', '');
    }
}
