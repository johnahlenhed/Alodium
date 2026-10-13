<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stevebauman\Location\Facades\Location;
use Stevebauman\Location\Position;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tracker_flow_creates_visit_updates_it_and_stores_event(): void
    {
        $position = new Position;
        $position->countryCode = 'SE';
        Location::shouldReceive('get')->andReturn($position);

        $created = $this->postJson('/api/collect', [
            'url' => '/blog',
            'utm_source' => 'google',
            'device_type' => 'mobile',
            'screen_width' => 390,
        ])->assertOk()->assertJsonStructure(['visit_id', 'token']);
        $id = $created->json('visit_id');
        $token = $created->json('token');
        $this->assertSame(40, strlen($token));

        $this->assertDatabaseHas('visits', ['id' => $id, 'url' => '/blog', 'country' => 'SE']);

        $this->patchJson("/api/collect/{$id}", ['token' => $token, 'duration' => 42, 'scroll_depth' => 80])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('visits', ['id' => $id, 'duration' => 42, 'scroll_depth' => 80]);

        $this->postJson('/api/events', [
            'visit_id' => $id,
            'token' => $token,
            'type' => 'outbound_click',
            'payload' => ['url' => 'https://example.com'],
        ])->assertOk();
        $this->assertSame(['url' => 'https://example.com'], Event::first()->payload);
    }

    public function test_update_requires_the_visits_own_token(): void
    {
        $mine = Visit::create(['url' => '/', 'token' => 'a-secret-token']);
        $other = Visit::create(['url' => '/', 'token' => 'someone-elses-token']);
        $untokened = Visit::create(['url' => '/']);

        $this->patchJson("/api/collect/{$mine->id}", ['duration' => 5])->assertStatus(422);
        $this->patchJson("/api/collect/{$mine->id}", ['token' => 'wrong', 'duration' => 5])->assertForbidden();
        $this->patchJson("/api/collect/{$mine->id}", ['token' => $other->token, 'duration' => 5])->assertForbidden();
        $this->patchJson("/api/collect/{$untokened->id}", ['token' => 'anything', 'duration' => 5])->assertForbidden();

        $this->assertNull($mine->fresh()->duration);
        $this->assertNull($untokened->fresh()->duration);

        $this->patchJson("/api/collect/{$mine->id}", ['token' => 'a-secret-token', 'duration' => 5])->assertOk();
        $this->assertSame(5, $mine->fresh()->duration);
    }

    public function test_events_require_the_visits_own_token(): void
    {
        $mine = Visit::create(['url' => '/', 'token' => 'a-secret-token']);
        $other = Visit::create(['url' => '/', 'token' => 'someone-elses-token']);
        $untokened = Visit::create(['url' => '/']);
        $event = fn (Visit $visit, array $extra = []) => $this->postJson('/api/events', [
            'visit_id' => $visit->id, 'type' => 'outbound_click', ...$extra,
        ]);

        $event($mine)->assertForbidden();
        $event($mine, ['token' => 'wrong'])->assertForbidden();
        $event($mine, ['token' => $other->token])->assertForbidden();
        $event($untokened, ['token' => 'anything'])->assertForbidden();
        $this->assertSame(0, Event::count());

        $event($mine, ['token' => 'a-secret-token'])->assertOk();
        $this->assertSame(1, Event::count());
    }

    public function test_tracking_validation_and_cors(): void
    {
        $this->postJson('/api/collect', [])->assertStatus(422);
        $this->postJson('/api/events', ['visit_id' => 999, 'type' => 'x'])->assertStatus(422);

        $this->call('OPTIONS', '/api/collect', [], [], [], [
            'HTTP_ORIGIN' => 'https://client.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_dashboard_requires_auth_and_returns_aggregates(): void
    {
        $this->get('/analytics')->assertRedirect('/login');

        Visit::create(['url' => '/', 'device_type' => 'mobile', 'country' => 'SE', 'duration' => 10, 'scroll_depth' => 50]);
        Visit::create(['url' => '/', 'device_type' => 'desktop', 'duration' => 20, 'scroll_depth' => 100]);

        $this->actingAs(User::factory()->create())
            ->get('/analytics?days=7')
            ->assertInertia(fn ($page) => $page
                ->component('Analytics/Index')
                ->where('totalVisits', 2)
                ->where('avgDuration', 15)
                ->where('avgScrollDepth', 75)
                ->where('days', 7)
                ->has('visitsOverTime', 7)
                ->where('topPages.0', ['label' => '/', 'count' => 2])
                ->has('topCountries', 1));
    }

    public function test_visit_seeder_generates_visits_within_30_days_and_some_events(): void
    {
        $this->seed(\Database\Seeders\VisitSeeder::class);

        $this->assertSame(200, Visit::count());
        $this->assertSame(0, Visit::where('created_at', '<', now()->subDays(31))->count());
        $this->assertGreaterThan(0, Event::count());
        $this->assertLessThan(100, Event::count());
    }
}
