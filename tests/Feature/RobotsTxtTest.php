<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * robots.txt files are static: Nginx serves public/robots.txt for the admin
 * panel, and Astro copies astro/public/robots.txt into the built site. A
 * Laravel feature test never reaches the web server (GET /robots.txt would
 * be a 404 from Laravel's router), so these tests check the files themselves.
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

    public function test_public_site_robots_txt_allows_crawling_and_points_to_the_astro_sitemap(): void
    {
        $robots = file_get_contents(base_path('astro/public/robots.txt'));

        $this->assertMatchesRegularExpression('/^Allow: \/$/m', $robots);
        // The sitemap lives on the Astro site (port 8080), not the Laravel app.
        $this->assertMatchesRegularExpression('#^Sitemap: https?://[^/\s]+:8080/sitemap-index\.xml$#m', $robots);
        $this->assertStringContainsString('# Update Sitemap URL when a domain is configured', $robots);
    }
}
