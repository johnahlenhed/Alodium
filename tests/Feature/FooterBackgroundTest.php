<?php

namespace Tests\Feature;

use App\Rules\FooterBackgroundColor;
use App\Support\FooterBackground;
use App\Support\NavBackground;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The PHP footer background rule must pick the same scheme for exactly the
 * colours the public site does. tests/Fixtures/footer-backgrounds.json was
 * generated from astro/src/lib/footerBackground.js (see generate-footer-backgrounds.mjs).
 */
class FooterBackgroundTest extends TestCase
{
    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/footer-backgrounds.json')), true, flags: JSON_THROW_ON_ERROR)['cases'];
    }

    private function message(string $value, string $brand = '#1B2A4A'): string
    {
        return Validator::make(['c' => $value], ['c' => [new FooterBackgroundColor($brand)]])->errors()->first('c');
    }

    public function test_the_fixture_has_every_verdict_for_every_brand(): void
    {
        foreach (collect($this->fixture())->groupBy('brand') as $brand => $cases) {
            foreach (['light', 'dark', null] as $scheme) {
                $this->assertGreaterThan(10, $cases->where('scheme', $scheme)->count(), "{$brand}: ".json_encode($scheme));
            }
        }
    }

    public function test_php_picks_the_same_scheme_as_the_public_site(): void
    {
        $mismatches = [];
        foreach ($this->fixture() as $case) {
            foreach (FooterBackground::SCHEMES as $scheme) {
                $ratios = array_column(FooterBackground::checks($case['background'], $case['brand'], $scheme), 'ratio');
                foreach ($case['ratios'][$scheme] as $i => $ratio) {
                    $this->assertEqualsWithDelta($ratio, $ratios[$i], 1e-12, "{$case['brand']} on {$case['background']}, {$scheme} {$i}");
                }
            }
            $worst = array_map(fn (?array $check) => $check['what'] ?? null, FooterBackground::worst($case['background'], $case['brand']));
            if (FooterBackground::scheme($case['background'], $case['brand']) !== $case['scheme'] || $worst !== $case['worst']) {
                $mismatches[] = "{$case['brand']} on {$case['background']}";
            }
        }

        $this->assertSame([], $mismatches, 'Cases the PHP rule and footerBackground.js disagree on');
    }

    public function test_the_footers_own_checks_decide_not_the_headers(): void
    {
        // The light grey the footer has now passes; so does the brand colour (white text).
        $this->assertSame('light', FooterBackground::scheme('#F5F6F8', '#1B2A4A'));
        $this->assertSame('dark', FooterBackground::scheme('#1B2A4A', '#1B2A4A'));
        // A grey the header can use with dark text is too dark for the footer's muted text.
        $this->assertSame('light', NavBackground::scheme('#9E9E9E', '#1B2A4A'));
        $this->assertNull(FooterBackground::scheme('#9E9E9E', '#1B2A4A'));
    }

    public function test_a_refused_colour_names_the_worst_check_of_both_schemes(): void
    {
        $this->assertSame('', $this->message('#F5F6F8'));
        $this->assertSame(
            'Neither dark nor white text works on this colour. With dark text, the footer text and the analytics note at 2.3:1 (minimum 4.5:1); with white text, the site name, menu links and footer text at 2.6:1 (minimum 4.5:1). Try a lighter or a darker shade.',
            $this->message('#9E9E9E'),
        );
    }

    public function test_only_six_digit_hex_colours_are_accepted(): void
    {
        foreach (['#fff', 'grey', 'F5F6F8', '#F5F6F8 ', "#F5F6F8\n", 'red;}footer{display:none', 'transparent'] as $value) {
            $this->assertNull(FooterBackground::scheme($value, '#1B2A4A'), json_encode($value));
            $this->assertSame('Enter the colour as # followed by 6 hexadecimal digits, for example #1B2A4A.', $this->message($value), json_encode($value));
        }
    }
}
