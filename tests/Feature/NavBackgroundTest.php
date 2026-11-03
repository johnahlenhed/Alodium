<?php

namespace Tests\Feature;

use App\Rules\NavBackgroundColor;
use App\Support\NavBackground;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The PHP header background rule must pick the same scheme for exactly the
 * colours the public site does. tests/Fixtures/nav-backgrounds.json was
 * generated from astro/src/lib/navBackground.js (see generate-nav-backgrounds.mjs).
 */
class NavBackgroundTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/nav-backgrounds.json')), true, flags: JSON_THROW_ON_ERROR)['cases'];
    }

    private function message(string $value, string $brand = '#1B2A4A'): string
    {
        return Validator::make(['c' => $value], ['c' => [new NavBackgroundColor($brand)]])->errors()->first('c');
    }

    public function test_the_fixture_has_every_verdict_for_every_brand(): void
    {
        foreach (collect($this->fixture())->groupBy('brand') as $brand => $cases) {
            foreach (['light', 'dark', null] as $scheme) {
                $this->assertGreaterThan(10, $cases->where('scheme', $scheme)->count(), "{$brand}: ".json_encode($scheme));
            }
        }
        // The brand colour decides some: the same colour passes with one brand and not another.
        $this->assertNotEmpty(collect($this->fixture())->groupBy('background')->filter(fn ($cases) => $cases->pluck('scheme')->unique()->count() > 1));
    }

    public function test_php_picks_the_same_scheme_as_the_public_site(): void
    {
        $mismatches = [];
        foreach ($this->fixture() as $case) {
            foreach (NavBackground::SCHEMES as $scheme) {
                $ratios = array_column(NavBackground::checks($case['background'], $case['brand'], $scheme), 'ratio');
                foreach ($case['ratios'][$scheme] as $i => $ratio) {
                    $this->assertEqualsWithDelta($ratio, $ratios[$i], 1e-12, "{$case['brand']} on {$case['background']}, {$scheme} {$i}");
                }
            }
            $worst = array_map(fn (?array $check) => $check['what'] ?? null, NavBackground::worst($case['background'], $case['brand']));
            if (NavBackground::scheme($case['background'], $case['brand']) !== $case['scheme'] || $worst !== $case['worst']) {
                $mismatches[] = "{$case['brand']} on {$case['background']}";
            }
        }

        $this->assertSame([], $mismatches, 'Cases the PHP rule and navBackground.js disagree on');
    }

    public function test_a_refused_colour_names_the_worst_check_of_both_schemes(): void
    {
        $this->assertSame('', $this->message('#FFFFFF'));
        $this->assertSame('', $this->message('#1B2A4A'), 'The brand colour itself takes white text.');
        // Mid grey with a light brand: too light for white text, too dark for the darker brand shade.
        $this->assertSame(
            'Neither dark nor white text works on this colour. With dark text, the current page and hovered links at 1.9:1 (minimum 4.5:1); with white text, the site name and menu links at 3.5:1 (minimum 4.5:1). Try a lighter or a darker shade.',
            $this->message('#888888', '#767676'),
        );
        // The same grey with a dark brand: dark text works.
        $this->assertSame('', $this->message('#888888', '#1B2A4A'));
    }

    public function test_only_six_digit_hex_colours_are_accepted(): void
    {
        foreach (['#fff', 'navy', '1B2A4A', '#1B2A4A ', "#1B2A4A\n", 'red;}body{display:none', 'transparent'] as $value) {
            $this->assertNull(NavBackground::scheme($value, '#1B2A4A'), json_encode($value));
            $this->assertSame('Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.', $this->message($value), json_encode($value));
        }
    }
}
