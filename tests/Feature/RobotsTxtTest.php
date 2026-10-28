<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Nginx serves public/robots.txt for the admin panel as a static file. A Laravel
 * feature test never reaches the web server (GET /robots.txt would be a 404 from
 * Laravel's router), so these tests check the file itself. The public site's
 * robots.txt is built by Astro (astro/src/pages/robots.txt.ts) and checked in the
 * build output by astro/scripts/check-build.mjs.
 */
class RobotsTxtTest extends TestCase
{
    public function test_admin_panel_robots_txt_blocks_all_crawlers(): void
    {
        $path = public_path('robots.txt');

        $this->assertFileExists($path);
        $robots = file_get_contents($path);

        $this->assertMatchesRegularExpression('/^User-agent: \*$/m', $robots);
        // "Disallow: /" exactly. Laravel's default "Disallow:" (empty) allows everything.
        $this->assertMatchesRegularExpression('/^Disallow: \/\s*$/m', $robots);
        $this->assertStringNotContainsStringIgnoringCase('sitemap', $robots);
    }

    public function test_no_laravel_route_shadows_the_static_file(): void
    {
        // If a route answered /robots.txt, it would replace the static file.
        $this->get('/robots.txt')->assertNotFound();
    }

    public function test_public_site_robots_txt_is_built_not_a_static_file(): void
    {
        // A static copy (an old checkout or merge) would carry a fixed Sitemap URL
        // instead of the one built from SITE_URL.
        $this->assertFileExists(base_path('astro/src/pages/robots.txt.ts'));
        $this->assertFileDoesNotExist(base_path('astro/public/robots.txt'));
    }
}
