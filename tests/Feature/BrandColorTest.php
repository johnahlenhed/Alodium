<?php

namespace Tests\Feature;

use App\Rules\BrandColor;
use App\Support\ColorContrast;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The PHP brand colour rule must accept exactly the colours the public site
 * accepts. tests/Fixtures/brand-colours.json was generated from
 * astro/src/lib/color.js and theme.js (see generate-brand-colours.mjs).
 */
class BrandColorTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/brand-colours.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    private function passes(string $value): bool
    {
        return Validator::make(['brand_color' => $value], ['brand_color' => [new BrandColor]])->passes();
    }

    public function test_the_fixture_is_large_enough_and_covers_both_sides_of_the_limit(): void
    {
        $fixture = $this->fixture();

        $this->assertGreaterThanOrEqual(300, count($fixture['colours']));
        $this->assertSame(4.5, (float) $fixture['minimum']);
        $ratios = array_column($fixture['colours'], 'ratio');
        // Borderline colours within a millionth of the limit on both sides.
        $this->assertNotEmpty(array_filter($ratios, fn ($r) => $r < 4.5 && $r > 4.5 - 1e-6));
        $this->assertNotEmpty(array_filter($ratios, fn ($r) => $r >= 4.5 && $r < 4.5 + 1e-6));
    }

    public function test_php_accepts_exactly_the_colours_color_js_accepts(): void
    {
        $mismatches = [];
        foreach ($this->fixture()['colours'] as $colour) {
            $ratio = ColorContrast::againstWhite($colour['hex']);

            // Same maths in the same order: the ratios agree to floating-point precision.
            $this->assertEqualsWithDelta($colour['ratio'], $ratio, 1e-12, $colour['hex']);
            if ($this->passes($colour['hex']) !== $colour['accepted']) {
                $mismatches[] = $colour['hex'];
            }
        }

        $this->assertSame([], $mismatches, 'Colours the PHP rule and color.js disagree on');
    }

    public function test_the_message_for_a_light_colour_is_friendly_and_never_rounds_up_to_the_limit(): void
    {
        $message = fn (string $hex) => Validator::make(['c' => $hex], ['c' => [new BrandColor]])->errors()->first('c');

        $this->assertSame(
            'This colour is too light: white text on it would be hard to read (contrast 1.0:1, minimum 4.5:1). Try a darker shade.',
            $message('#FFFF00'),
        );
        // #777777 is 4.478:1: rounding would show "4.5:1"; it is shown as 4.4.
        $this->assertStringContainsString('(contrast 4.4:1, minimum 4.5:1)', $message('#777777'));
    }

    public function test_only_six_digit_hex_colours_are_accepted(): void
    {
        foreach (['#fff', '#000', 'red', '1B2A4A', '#1B2A4G', '#1B2A4A00', ' #1B2A4A', '#1B2A4A ', "#1B2A4A\n", 'rgb(27,42,74)', 'red; background:url(//evil)'] as $value) {
            $this->assertFalse($this->passes($value), json_encode($value));
        }
        $this->assertSame(
            'Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.',
            Validator::make(['c' => '#fff'], ['c' => [new BrandColor]])->errors()->first('c'),
        );
        $this->assertTrue($this->passes('#1b2a4a'));
        $this->assertTrue($this->passes('#1B2A4A'));
    }
}
