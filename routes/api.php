<?php

use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

// Public, unauthenticated endpoints called by tracker.js from any origin.
// Throttled per IP since anyone can reach them.
Route::middleware('throttle:120,1')->group(function () {
    Route::post('/collect', [TrackingController::class, 'store']);
    Route::patch('/collect/{visit}', [TrackingController::class, 'update']);
    Route::post('/events', [TrackingController::class, 'storeEvent']);
});
