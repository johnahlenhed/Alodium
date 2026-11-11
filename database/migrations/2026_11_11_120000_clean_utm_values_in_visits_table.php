<?php

use App\Support\UtmValue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cleans the utm_source, utm_medium and utm_campaign values stored before
 * App\Support\UtmValue existed, with the same rules as new page views:
 * lowercase (so "Newsletter" and "newsletter" are one source in the reports),
 * at most 100 characters, and a value that looks like an email address or a
 * personal id is removed. The page views themselves are kept.
 *
 * Not reversible: the original case and the removed values are gone. Running
 * it again changes nothing (cleaning a clean value gives the same value).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('visits')
            ->where(fn ($query) => $query->whereNotNull('utm_source')->orWhereNotNull('utm_medium')->orWhereNotNull('utm_campaign'))
            ->select(['id', ...UtmValue::FIELDS])
            ->chunkById(1000, function ($visits) {
                foreach ($visits as $visit) {
                    $stored = (array) $visit;
                    $cleaned = UtmValue::fromInput($stored);
                    $changed = array_filter($cleaned, fn ($value, $field) => $value !== $stored[$field], ARRAY_FILTER_USE_BOTH);
                    if ($changed !== []) {
                        DB::table('visits')->where('id', $visit->id)->update($changed);
                    }
                }
            });
    }

    public function down(): void
    {
        // Nothing to undo: see above.
    }
};
