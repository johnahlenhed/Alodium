<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PostController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

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
});

// Admin only: deleting content, analytics, import, user management.
Route::middleware(['auth', 'admin'])->group(function () {
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('posts.destroy');
    Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
    Route::delete('/media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');

    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');

    Route::get('/import', [ImportController::class, 'create'])->name('import.create');
    Route::post('/import', [ImportController::class, 'store'])->name('import.store');
    Route::get('/import/result', [ImportController::class, 'result'])->name('import.result');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

require __DIR__.'/auth.php';
