<?php

namespace App\Http\Controllers;

use App\Services\PublicSiteSettings;
use Illuminate\Http\JsonResponse;

/** The public site's settings for the Astro build (name, theme, logo, navigation, front page). */
class PublicSiteController extends Controller
{
    public function __invoke(PublicSiteSettings $site): JsonResponse
    {
        return response()->json($site->payload());
    }
}
