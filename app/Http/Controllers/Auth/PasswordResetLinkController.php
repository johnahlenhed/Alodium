<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming password reset link request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Laravel's ResetPassword notification is not queued: the email is sent
        // during this request, so a mail failure (no or invalid RESEND_API_KEY,
        // unverified domain, Resend unreachable) is thrown here. Uncaught, it was
        // a 500 instead of the form.
        try {
            $status = Password::sendResetLink(
                $request->only('email')
            );
        } catch (Throwable $e) {
            Log::error('Password reset email failed.', ['email' => $request->input('email'), 'error' => $e->getMessage()]);

            // The token was stored before the send failed. Nobody received it, and
            // left in place it would make a retry within a minute fail with "Please
            // wait before retrying" instead of trying again.
            $user = Password::getUser($request->only('email'));
            if ($user) {
                Password::deleteToken($user);
            }

            throw ValidationException::withMessages([
                'email' => ["The reset email couldn't be sent. Please try again later, or contact the site's administrator."],
            ]);
        }

        if ($status == Password::RESET_LINK_SENT) {
            return back()->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans($status)],
        ]);
    }
}
