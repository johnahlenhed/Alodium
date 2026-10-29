<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /api/public/*: 60 requests a minute per IP, except from the server itself,
 * where the Astro build runs. Addresses are from the documentation ranges.
 */
class PublicContentRateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** The server's own public IP (SERVER_ADDR: the address a request arrived on). */
    private const SERVER_IP = '198.51.100.10';

    private const VISITOR_IP = '203.0.113.7';

    /** @var list<string> */
    private array $postSlugs = [];

    /** @var list<string> */
    private array $pageSlugs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $author = User::factory()->create();
        foreach (['post' => Post::class, 'page' => Page::class] as $type => $model) {
            foreach (range(1, $type === 'post' ? 40 : 20) as $i) {
                $model::create(['author_id' => $author->id, 'title' => "{$type} {$i}", 'slug' => "{$type}-{$i}", 'content' => '<p>Text</p>', 'status' => 'published', 'published_at' => now()->subDay()]);
            }
        }
        $this->postSlugs = Post::pluck('slug')->all();
        $this->pageSlugs = Page::pluck('slug')->all();
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip, 'SERVER_ADDR' => self::SERVER_IP]);
    }

    /**
     * The requests of one build, as counted in a real local build (src/lib/api.js and
     * the pages that call it): /posts three times, /pages and /site once, then every
     * post and page. 65 here, more than the public limit on its own.
     *
     * @return list<string>
     */
    private function buildRequests(): array
    {
        return [
            '/api/public/site', '/api/public/posts', '/api/public/posts', '/api/public/posts', '/api/public/pages',
            ...array_map(fn ($slug) => "/api/public/posts/{$slug}", $this->postSlugs),
            ...array_map(fn ($slug) => "/api/public/pages/{$slug}", $this->pageSlugs),
        ];
    }

    public function test_a_client_is_throttled_after_60_requests_a_minute_across_all_endpoints(): void
    {
        $this->fromIp(self::VISITOR_IP);
        // One counter for all five endpoints: a scraper can't spread its requests over them.
        foreach (array_slice($this->buildRequests(), 0, 60) as $url) {
            $this->getJson($url)->assertOk();
        }

        $this->getJson('/api/public/posts/post-1')->assertStatus(429)->assertHeader('Retry-After');
        $this->getJson('/api/public/site')->assertStatus(429);

        // A spoofed X-Forwarded-For does not help: no proxy is trusted, so the IP is REMOTE_ADDR.
        $this->withHeader('X-Forwarded-For', '127.0.0.1')->getJson('/api/public/pages')->assertStatus(429);

        // Per IP: another visitor is not affected.
        $this->fromIp('203.0.113.8')->getJson('/api/public/posts')->assertOk();

        // A minute later the client may continue.
        $this->travel(61)->seconds();
        $this->fromIp(self::VISITOR_IP)->getJson('/api/public/posts')->assertOk();
    }

    public static function buildSources(): array
    {
        return [
            'IPv4 loopback (local build)' => ['127.0.0.1'],
            'IPv6 loopback' => ['::1'],
            "the server's own public IP (production build via the public domain)" => [self::SERVER_IP],
        ];
    }

    #[DataProvider('buildSources')]
    public function test_astro_build_traffic_from_the_server_itself_is_never_throttled(string $ip): void
    {
        $this->fromIp($ip);
        // Two builds back to back (a rebuild queued during a build runs right after it):
        // 130 requests within the minute, more than twice the public limit.
        $requests = [...$this->buildRequests(), ...$this->buildRequests()];
        $this->assertGreaterThan(120, count($requests));

        foreach ($requests as $url) {
            $this->getJson($url)->assertOk();
        }
    }

    public function test_the_contact_form_keeps_its_own_stricter_limit(): void
    {
        // Not part of the public-content limiter: its 5 a minute still apply, and using
        // them up does not count against the content endpoints.
        $this->fromIp(self::VISITOR_IP);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/public/contact', [])->assertStatus(422);
        }
        $this->postJson('/api/public/contact', [])->assertStatus(429);
        $this->getJson('/api/public/posts')->assertOk();
    }
}
