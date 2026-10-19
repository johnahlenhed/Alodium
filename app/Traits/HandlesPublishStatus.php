<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Shared by PostController and PageController.
 *
 * The form offers three choices, but the database only stores two statuses:
 * "scheduled" is saved as status=draft with a publish_at date. The
 * content:publish-scheduled command publishes it when that time arrives.
 */
trait HandlesPublishStatus
{
    protected function publishStatusRules(): array
    {
        return [
            'status' => ['required', 'in:draft,published,scheduled'],
            // Ignored (and removed from the validated data) unless scheduling.
            'publish_at' => ['exclude_unless:status,scheduled', 'required', 'date', 'after:now'],
        ];
    }

    /** status / published_at / publish_at to store, for the status chosen in the form. */
    protected function publishStatusAttributes(array $validated, ?Model $existing = null): array
    {
        return match ($validated['status']) {
            'scheduled' => [
                'status' => 'draft',
                // Normalised to UTC here: Eloquent stores a string such as
                // "2026-10-20T14:30+02:00" as 14:30, silently dropping the offset.
                'publish_at' => Carbon::parse($validated['publish_at'])->utc(),
                // published_at is left alone: the scheduler sets it when publishing.
            ],
            'published' => [
                'status' => 'published',
                // Only stamped the first time, so re-saving keeps the original date.
                'published_at' => $existing?->published_at ?? now(),
                // A manual publish replaces any pending schedule.
                'publish_at' => null,
            ],
            default => [
                'status' => 'draft',
                'publish_at' => null,
            ],
        };
    }
}
