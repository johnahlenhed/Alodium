<?php

namespace Tests\Feature;

use App\Rules\PageBackgroundColor;
use App\Support\PageBackground;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The PHP page background rule must accept exactly the colours the public site
 * accepts. tests/Fixtures/page-backgrounds.json was generated from
 * astro/src/lib/pageBackground.js (see generate-page-backgrounds.mjs).
 */
class PageBackgroundTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/page-backgrounds.json')), true, flags: JSON_THROW_ON_ERROR)['cases'];
    }

    public function test_the_fixture_has_both_verdicts_for_every_brand(): void
    {
        foreach (collect($this->fixture())->groupBy('brand') as $brand => $cases) {
            $this->assertGreaterThan(20, $cases->where('accepted', true)->count(), $brand);
            $this->assertGreaterThan(20, $cases->where('accepted', false)->count(), $brand);
        }
    }

    public function test_php_accepts_exactly_the_page_backgrounds_the_public_site_accepts(): void
    {
        $mismatches = [];
        foreach ($this->fixture() as $case) {
            // Same maths in the same order: every ratio agrees to floating-point precision.
            $ratios = array_column(PageBackground::checks($case['background'], $case['brand']), 'ratio');
            foreach ($case['ratios'] as $i => $ratio) {
                $this->assertEqualsWithDelta($ratio, $ratios[$i], 1e-12, "{$case['brand']} on {$case['background']}, check {$i}");
            }
            if (PageBackground::isValid($case['background'], $case['brand']) !== $case['accepted']
                || (PageBackground::worst($case['background'], $case['brand'])['what'] ?? null) !== $case['worst']) {
                $mismatches[] = "{$case['brand']} on {$case['background']}";
            }
        }

        $this->assertSame([], $mismatches, 'Cases the PHP rule and pageBackground.js disagree on');
    }

    public function test_the_message_names_what_would_be_hard_to_read_and_never_rounds_up(): void
    {
        $message = fn (string $value) => Validator::make(['c' => $value], ['c' => [new PageBackgroundColor('#1B2A4A')]])->errors()->first('c');

        $this->assertSame('', $message('#FAF7F2'));
        $this->assertSame(
            // On the brand colour itself, the dark brand shade of the links is the worst.
            'This colour is too dark for the page: links and button text would be hard to read on it (contrast 1.1:1, minimum 4.5:1). Try a lighter shade.',
            $message('#1B2A4A'),
        );
        // The lowest relative to its minimum is named, rounded down (never shown as reaching the minimum).
        $this->assertMatchesRegularExpression('/^This colour is too dark for the page: .+ \(contrast 4\.[0-4]:1, minimum 4\.5:1\)\. Try a lighter shade\.$/', $message('#E0E0E0'));
    }

    public function test_only_six_digit_hex_colours_are_accepted(): void
    {
        foreach (['#fff', 'white', 'FAF7F2', '#FAF7F2 ', "#FAF7F2\n", '#FAF7F2; background:url(//evil)', 'rgb(250,247,242)', 'transparent'] as $value) {
            $this->assertFalse(PageBackground::isValid($value, '#1B2A4A'), json_encode($value));
            $this->assertSame(
                'Enter the colour as # followed by 6 hexadecimal digits, for example #F5F1EA.',
                Validator::make(['c' => $value], ['c' => [new PageBackgroundColor('#1B2A4A')]])->errors()->first('c'),
                json_encode($value),
            );
        }
    }
}
