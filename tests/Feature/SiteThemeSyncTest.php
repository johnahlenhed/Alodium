<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\SiteSetting;
use App\Support\ThemeOptions;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The PHP theme defaults and allowed values must equal the public site's.
 * Rather than parsing the JavaScript, this loads astro/src/lib/theme.js and
 * fonts.js with Node and compares the real exported values, so a reformat of
 * those files can't break the test and a real change can't slip through.
 */
class SiteThemeSyncTest extends TestCase
{
    private function astroTheme(): array
    {
        $script = <<<'JS'
            const theme = await import('./astro/src/lib/theme.js');
            const fonts = await import('./astro/src/lib/fonts.js');
            console.log(JSON.stringify({
                defaults: theme.DEFAULT_SETTINGS,
                fonts: Object.keys(fonts.FONTS),
                corners: theme.CORNERS,
                buttons: Object.keys(theme.BUTTON_STYLES),
                minContrast: theme.MIN_BRAND_CONTRAST,
            }));
            JS;

        $result = Process::path(base_path())->run(['node', '--input-type=module', '-e', $script]);
        if ($result->exitCode() === 127 || str_contains($result->errorOutput(), 'not found')) {
            $this->markTestSkipped('Node.js is not installed, so the Astro theme cannot be loaded.');
        }
        $this->assertTrue($result->successful(), $result->errorOutput());

        return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_the_defaults_and_allowed_values_equal_the_astro_theme(): void
    {
        $astro = $this->astroTheme();

        $this->assertSame($astro['defaults'], ThemeOptions::DEFAULTS);
        $this->assertSame($astro['fonts'], array_keys(ThemeOptions::FONTS));
        $this->assertSame(array_keys($astro['corners']), array_keys(ThemeOptions::CORNERS));
        $this->assertSame($astro['corners'], array_map(fn (array $corner) => $corner['radius'], ThemeOptions::CORNERS));
        $this->assertSame($astro['buttons'], array_keys(ThemeOptions::BUTTONS));
        $this->assertEquals($astro['minContrast'], ThemeOptions::MIN_BRAND_CONTRAST);
    }

    public function test_the_model_defaults_use_the_theme_defaults(): void
    {
        $defaults = SiteSetting::defaults();

        $this->assertSame(ThemeOptions::DEFAULTS, [
            'brand' => $defaults['brand_color'],
            'fontBody' => $defaults['font_body'],
            'fontHeading' => $defaults['font_heading'],
            'corners' => $defaults['corners'],
            'buttons' => $defaults['buttons'],
        ]);
        $this->assertSame(config('app.name'), $defaults['site_name']);
    }

    public function test_the_reserved_slugs_equal_the_ones_the_astro_page_route_skips(): void
    {
        $route = file_get_contents(base_path('astro/src/pages/[slug].astro'));
        $this->assertSame(1, preg_match('/const RESERVED = \[([^\]]*)\]/', $route, $match), 'RESERVED not found in [slug].astro');

        preg_match_all("/'([^']+)'/", $match[1], $slugs);
        $this->assertSame($slugs[1], Page::RESERVED_SLUGS);
    }
}
