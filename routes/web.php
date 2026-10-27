<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SiteSettingsController;
use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// The admin domain has no public home page: "/" opens the dashboard, and the
// auth middleware sends guests to the login page first.
Route::get('/', fn () => redirect()->route('dashboard'))->middleware('auth');

Route::get('/dashboard', DashboardController::class)->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Editors can create and edit; deleting is admin-only (below). Which items an
    // editor may edit is decided by PostPolicy/PagePolicy.
    Route::resource('posts', PostController::class)->except(['show', 'destroy']);
    Route::resource('pages', PageController::class)->except(['show', 'destroy']);

    // Revision history. scopeBindings(): {revision} must belong to the {post}/{page}
    // in the URL, so one item's revision can't be restored onto another.
    Route::scopeBindings()->group(function () {
        Route::get('/posts/{post}/revisions', [PostController::class, 'revisions'])
            ->name('posts.revisions');
        Route::post('/posts/{post}/revisions/{revision}/restore', [PostController::class, 'restoreRevision'])
            ->name('posts.revisions.restore');
        Route::get('/pages/{page}/revisions', [PageController::class, 'revisions'])
            ->name('pages.revisions');
        Route::post('/pages/{page}/revisions/{revision}/restore', [PageController::class, 'restoreRevision'])
            ->name('pages.revisions.restore');
    });

    Route::get('/media', [MediaController::class, 'index'])->name('media.index');
    // JSON for the OG image picker. In web.php (not api.php) so it uses the admin session login.
    Route::get('/api/media', [MediaController::class, 'list'])->name('media.list');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');

    // Contact form submissions: admins and editors read them, only admins delete (below).
    Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');
    Route::get('/submissions/{submission}', [SubmissionController::class, 'show'])->name('submissions.show');
    Route::patch('/submissions/{submission}/unread', [SubmissionController::class, 'markUnread'])->name('submissions.unread');
});

// Admin only: deleting content, analytics, import, user management, site settings.
Route::middleware(['auth', 'admin'])->group(function () {
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
    Route::delete('/media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');
    Route::delete('/submissions/{submission}', [SubmissionController::class, 'destroy'])->name('submissions.destroy');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    Route::get('/import', [ImportController::class, 'create'])->name('import.create');
    Route::post('/import', [ImportController::class, 'store'])->name('import.store');
    Route::get('/import/result', [ImportController::class, 'result'])->name('import.result');
    Route::get('/import/runs/{run}/status', [ImportController::class, 'status'])->name('import.runs.status');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

    // The public site's identity and look. PUT: the form sends it as POST with _method=PUT,
    // because a file upload needs multipart and browsers only send multipart with POST.
    Route::get('/settings', [SiteSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SiteSettingsController::class, 'update'])->name('settings.update');
});

require __DIR__.'/auth.php';
