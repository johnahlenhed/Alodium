<?php

namespace App\Http\Controllers;

use App\Mail\UserInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** User management. All routes are admin-only (see routes/web.php). */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at']),
        ]);
    }

    /**
     * Creates an account with a random password nobody knows, then sends Laravel's
     * standard password reset email so the new user sets their own password.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'in:admin,editor'],
        ]);

        $user = User::create([
            ...$validated,
            'password' => Str::random(32), // hashed by the model's "hashed" cast
        ]);

        // A regular password reset token, sent in a custom invitation email instead
        // of Laravel's "you requested a password reset" notification. If mail fails,
        // the account still exists and the admin is told how the user can get in.
        try {
            $token = Password::createToken($user);
            $resetUrl = url(route('password.reset', [
                'token' => $token,
                'email' => $user->email,
            ], false));

            Mail::to($user->email)->send(new UserInvitation($user, $resetUrl, $request->user()));
            $sent = true;
        } catch (Throwable $e) {
            Log::error('Welcome email for new user failed.', ['email' => $validated['email'], 'error' => $e->getMessage()]);
            $sent = false;
        }

        // Not "Forgot password?": that sends email too, so it fails the same way.
        if (! $sent) {
            return redirect()->route('users.index')->with('warning',
                "User created, but the invitation email couldn't be sent, so {$user->email} can't set a password yet. "
                ."Until email works, someone with access to the server (usually your developer) can set one with: php artisan user:set-password {$user->email}");
        }

        return redirect()->route('users.index')->with('success',
            "User created. An invitation email has been sent to {$validated['email']}. The link is valid for "
            .config('auth.passwords.users.expire').' minutes; after that, "Forgot password" on the login page sends a new one.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'in:admin,editor'],
        ]);

        // Demoting yourself could leave the site without any admin.
        abort_if($user->is($request->user()), 403, 'You cannot change your own role.');

        $user->update(['role' => $validated['role']]);

        return back()->with('success', "{$user->name} is now an {$user->role}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 403, 'You cannot delete your own account here.');

        $user->deleteAndTransferContentTo($request->user());

        return back()->with('success', "{$user->name} was deleted. Their posts, pages and media now belong to you.");
    }
}
