<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Stevebauman\Location\Facades\Location;
use Throwable;

class TrackingController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'referrer' => ['nullable', 'string', 'max:2048'],
            'utm_source' => ['nullable', 'string', 'max:255'],
            'utm_medium' => ['nullable', 'string', 'max:255'],
            'utm_campaign' => ['nullable', 'string', 'max:255'],
            'utm_content' => ['nullable', 'string', 'max:255'],
            'utm_term' => ['nullable', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:50'],
            'screen_width' => ['nullable', 'integer', 'min:0'],
            'language' => ['nullable', 'string', 'max:35'],
        ]);

        // Visit ids are sequential and guessable, so updates must prove they came from
        // the tracker that created the visit: the token is only ever returned here.
        $visit = Visit::create([
            ...$validated,
            'country' => $this->countryFor($request->ip()),
            'token' => Str::random(40),
        ]);

        return response()->json(['visit_id' => $visit->id, 'token' => $visit->token]);
    }

    public function update(Request $request, Visit $visit): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'duration' => ['nullable', 'integer', 'min:0'],
            'scroll_depth' => ['nullable', 'integer', 'between:0,100'],
        ]);

        abort_unless($this->tokenMatches($visit, $validated['token']), 403, 'Invalid token.');

        $visit->update(Arr::only($validated, ['duration', 'scroll_depth']));

        return response()->json(['success' => true]);
    }

    public function storeEvent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'visit_id' => ['required', 'integer', 'exists:visits,id'],
            'token' => ['nullable', 'string'],
            'type' => ['required', 'string', 'max:255'],
            'payload' => ['nullable', 'array'],
        ]);

        // A missing token is a 403 (not a validation error), same as a wrong one.
        abort_unless(
            $this->tokenMatches(Visit::findOrFail($validated['visit_id']), $validated['token'] ?? null),
            403,
            'Invalid token.'
        );

        Event::create(Arr::only($validated, ['visit_id', 'type', 'payload']));

        return response()->json(['success' => true]);
    }

    /**
     * Resolve a country code from the IP with the local GeoIP database. The IP
     * itself is never stored: only the resulting country code reaches the visits
     * table. Lookups are cached per IP (keyed by hash); a null result is not
     * cached, so visitors get a country as soon as a missing database is
     * installed. Any failure yields null, so geolocation never blocks tracking.
     */
    private function countryFor(?string $ip): ?string
    {
        if (! $ip) {
            return null;
        }

        try {
            return Cache::remember('geo:'.sha1($ip), now()->addDay(), function () use ($ip) {
                // false is "no result": the address isn't in the database (local and
                // private ones never are), or there is no database to read. The
                // dashboard's Site status warns about the second (SiteStatus::geoip()).
                $position = Location::get($ip);

                return $position === false ? null : ($position->countryCode ?: null);
            });
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Visits without a token (e.g. seeded data) never match. hash_equals compares in
     * constant time so the token can't be guessed by measuring response times.
     */
    private function tokenMatches(Visit $visit, ?string $token): bool
    {
        return $visit->token !== null && $token !== null && hash_equals($visit->token, $token);
    }
}
