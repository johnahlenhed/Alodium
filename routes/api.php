<?php

use App\Http\Controllers\PublicContentController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated endpoints called by tracker.js from any origin.
// Throttled per IP since anyone can reach them.
Route::middleware('throttle:120,1')->group(function () {
    Route::post('/collect', [TrackingController::class, 'store']);
    Route::patch('/collect/{visit}', [TrackingController::class, 'update']);
    Route::post('/events', [TrackingController::class, 'storeEvent']);
});

// Read-only published content for the Astro public site (fetched at build time).
Route::prefix('public')->group(function () {
    // 60 a minute per IP; the build, from this server, is not limited (AppServiceProvider).
    Route::middleware('throttle:public-content')->group(function () {
        Route::get('/posts', [PublicContentController::class, 'posts']);
        Route::get('/posts/{slug}', [PublicContentController::class, 'post']);
        Route::get('/pages', [PublicContentController::class, 'pages']);
        Route::get('/pages/{slug}', [PublicContentController::class, 'page']);
        // Site name, theme, logo, navigation and front page (Settings page).
        Route::get('/site', PublicSiteController::class);
    });

    // Each message sends an email, so this is limited much harder than the tracker.
    Route::post('/contact', [PublicContentController::class, 'contact'])->middleware('throttle:5,1');
});

// Secret-protected trigger for rebuilding the Astro site (X-Webhook-Secret header).
Route::post('/webhook/rebuild', [WebhookController::class, 'rebuild'])->middleware('throttle:10,1');
