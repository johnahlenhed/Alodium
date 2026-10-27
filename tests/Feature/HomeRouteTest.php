<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "/" on the admin domain: no public page, straight to the dashboard (or the login). */
class HomeRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_logged_in_users_are_sent_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect(route('dashboard'));
        $this->actingAs(User::factory()->editor()->create())->get('/')->assertRedirect(route('dashboard'));
    }
}
