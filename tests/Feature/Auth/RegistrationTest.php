<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_registration_is_disabled_by_default_in_production(): void
    {
        // The default is computed from APP_ENV when the config file is loaded,
        // so load the file again with APP_ENV set to production.
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'production';
        try {
            $this->assertFalse((require config_path('auth.php'))['registration']);
        } finally {
            putenv('APP_ENV=testing');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
        }
        $this->assertTrue((require config_path('auth.php'))['registration']);
    }

    public function test_disabled_registration_redirects_form_and_forbids_submission(): void
    {
        config(['auth.registration' => false]);

        $this->get('/register')->assertRedirect(route('login'));

        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertForbidden();

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }
}
