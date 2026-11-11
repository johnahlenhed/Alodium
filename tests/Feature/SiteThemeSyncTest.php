<?php

namespace Tests\Feature;

use App\Blocks\Types\SpacerBlock;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Support\LayoutOptions;
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

    public function test_the_header_and_footer_layouts_equal_the_ones_the_astro_build_accepts(): void
    {
        $result = Process::path(base_path())->run(['node', '--input-type=module', '-e',
            "const l = await import('./astro/src/lib/layouts.js'); console.log(JSON.stringify({ default: l.DEFAULT_LAYOUT, header: l.HEADER_LAYOUTS, footer: l.FOOTER_LAYOUTS }));"]);
        if ($result->exitCode() === 127 || str_contains($result->errorOutput(), 'not found')) {
            $this->markTestSkipped('Node.js is not installed, so the Astro layouts cannot be loaded.');
        }
        $this->assertTrue($result->successful(), $result->errorOutput());

        $this->assertSame(
            ['default' => LayoutOptions::DEFAULT, 'header' => array_keys(LayoutOptions::HEADER), 'footer' => array_keys(LayoutOptions::FOOTER)],
            json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertSame(LayoutOptions::DEFAULT, SiteSetting::defaults()['header_layout']);
        $this->assertSame(LayoutOptions::DEFAULT, SiteSetting::defaults()['footer_layout']);
    }

    public function test_the_logo_height_bounds_equal_the_ones_the_astro_build_accepts(): void
    {
        $result = Process::path(base_path())->run(['node', '--input-type=module', '-e',
            "const { LIMITS } = await import('./astro/src/lib/settings.js'); console.log(JSON.stringify(LIMITS.logoHeight));"]);
        if ($result->exitCode() === 127 || str_contains($result->errorOutput(), 'not found')) {
            $this->markTestSkipped('Node.js is not installed, so the Astro settings cannot be loaded.');
        }
        $this->assertTrue($result->successful(), $result->errorOutput());

        $this->assertSame(
            ['min' => SiteSetting::LOGO_HEIGHT_MIN, 'max' => SiteSetting::LOGO_HEIGHT_MAX],
            json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_the_header_padding_bounds_equal_the_ones_the_astro_build_accepts(): void
    {
        $result = Process::path(base_path())->run(['node', '--input-type=module', '-e',
            "const { LIMITS } = await import('./astro/src/lib/settings.js'); const { SITE_DEFAULTS } = await import('./astro/src/lib/site.js');"
            .' console.log(JSON.stringify({ ...LIMITS.headerPadding, default: SITE_DEFAULTS.headerPadding }));']);
        if ($result->exitCode() === 127 || str_contains($result->errorOutput(), 'not found')) {
            $this->markTestSkipped('Node.js is not installed, so the Astro settings cannot be loaded.');
        }
        $this->assertTrue($result->successful(), $result->errorOutput());

        $bounds = ['min' => SiteSetting::HEADER_PADDING_MIN, 'max' => SiteSetting::HEADER_PADDING_MAX, 'default' => SiteSetting::HEADER_PADDING_DEFAULT];
        $this->assertSame($bounds, json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame(SiteSetting::HEADER_PADDING_DEFAULT, SiteSetting::defaults()['header_padding']);
        // The Settings slider (its bounds and step come from the server) reaches both ends and the default.
        foreach ($bounds as $value) {
            $this->assertSame(0, ($value - SiteSetting::HEADER_PADDING_MIN) % SiteSetting::HEADER_PADDING_STEP, "{$value} is not on the slider");
        }
    }

    public function test_the_spacer_bounds_equal_the_ones_the_editor_and_the_astro_build_use(): void
    {
        $result = Process::path(base_path())->run(['node', '--input-type=module', '-e',
            "const astro = await import('./astro/src/lib/spacer.js'); const admin = await import('./resources/js/Components/Blocks/spacer.js');"
            .' const split = ({ step, ...bounds }) => ({ bounds, step });'
            .' console.log(JSON.stringify({ height: { astro: astro.SPACER_HEIGHT, ...split(admin.SPACER_HEIGHT) }, opacity: { astro: astro.SPACER_OPACITY, ...split(admin.SPACER_OPACITY) } }));']);
        if ($result->exitCode() === 127 || str_contains($result->errorOutput(), 'not found')) {
            $this->markTestSkipped('Node.js is not installed, so the spacer bounds cannot be loaded.');
        }
        $this->assertTrue($result->successful(), $result->errorOutput());

        $js = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        $php = [
            'height' => ['min' => SpacerBlock::HEIGHT_MIN, 'max' => SpacerBlock::HEIGHT_MAX, 'default' => SpacerBlock::HEIGHT_DEFAULT],
            'opacity' => ['min' => SpacerBlock::OPACITY_MIN, 'max' => SpacerBlock::OPACITY_MAX, 'default' => SpacerBlock::OPACITY_DEFAULT],
        ];
        foreach ($php as $field => $bounds) {
            $this->assertSame($bounds, $js[$field]['astro'], "{$field}: astro/src/lib/spacer.js");
            $this->assertSame($bounds, $js[$field]['bounds'], "{$field}: resources/js/Components/Blocks/spacer.js");
            // The editor's slider reaches both ends and the default in whole steps.
            foreach ($bounds as $value) {
                $this->assertSame(0, ($value - $bounds['min']) % $js[$field]['step'], "{$field}: {$value} is not on the slider");
            }
        }
    }

    public function test_the_reserved_slugs_equal_the_ones_the_astro_page_route_skips(): void
    {
        $route = file_get_contents(base_path('astro/src/pages/[slug].astro'));
        $this->assertSame(1, preg_match('/const RESERVED = \[([^\]]*)\]/', $route, $match), 'RESERVED not found in [slug].astro');

        preg_match_all("/'([^']+)'/", $match[1], $slugs);
        $this->assertSame($slugs[1], Page::RESERVED_SLUGS);
    }
}
