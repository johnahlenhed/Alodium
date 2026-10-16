<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks public registration when it is disabled (config auth.registration):
 * the form (GET) redirects to the login page, a submission (POST) gets 403.
 * Checked per request rather than by leaving the routes out, so it also works
 * with `php artisan route:cache`.
 */
class EnsureRegistrationIsEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('auth.registration')) {
            abort_unless($request->isMethodSafe(), 403, 'Registration is disabled.');

            return redirect()->route('login');
        }

        return $next($request);
    }
}
