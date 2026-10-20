<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** User management. All routes are admin-only (see routes/web.php). */
class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Users/Index', [
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'role', 'created_at']),
        ]);
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
