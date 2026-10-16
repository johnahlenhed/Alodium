<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_exits_without_creating_records_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--force' => true])
            ->expectsOutputToContain('Seeders are disabled in production')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Visit::count());
        $this->assertSame(0, Event::count());
    }

    public function test_visit_seeder_called_directly_creates_no_visits_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--class' => 'VisitSeeder', '--force' => true])
            ->expectsOutputToContain('VisitSeeder is disabled in production')
            ->assertSuccessful();

        $this->assertSame(0, Visit::count());
        $this->assertSame(0, Event::count());
    }

    public function test_seeder_creates_dev_data_outside_production(): void
    {
        $this->artisan('db:seed')->assertSuccessful();

        $this->assertTrue(User::where('email', 'john@alodium.dev')->exists());
        $this->assertSame(200, Visit::count());
    }
}
