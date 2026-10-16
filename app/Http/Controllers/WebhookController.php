<?php

namespace App\Http\Controllers;

use App\Jobs\RebuildAstroJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Lets external tools (CI, a cron job, curl) trigger a rebuild of the public site.
     * Content changes in the admin trigger rebuilds on their own; see PublicContentObserver.
     */
    public function rebuild(Request $request): JsonResponse
    {
        $secret = (string) config('services.astro.webhook_secret');
        $given = (string) $request->header('X-Webhook-Secret', '');

        // An unset secret must not mean "no secret required": hash_equals('', '') is true.
        // hash_equals compares in constant time, so the secret can't be guessed by timing.
        if ($secret === '' || ! hash_equals($secret, $given)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        RebuildAstroJob::dispatch();

        // 202: accepted for processing; the build runs later on the queue.
        return response()->json(['message' => 'Rebuild queued.'], 202);
    }
}
