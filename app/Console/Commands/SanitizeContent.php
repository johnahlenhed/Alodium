<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Post;
use App\Services\ContentSanitizer;
use Illuminate\Console\Command;

/**
 * Runs the stored `content` of every post and page through the shared sanitiser,
 * for HTML saved before content was sanitised on save. Changed items are saved
 * normally: published ones keep their old version as a revision, and a rebuild
 * of the public site is queued.
 */
class SanitizeContent extends Command
{
    protected $signature = 'content:sanitize {--dry-run : Only report how many posts and pages would change}';

    protected $description = 'Sanitise the stored HTML content of all posts and pages';

    public function handle(ContentSanitizer $sanitizer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        foreach (['posts' => Post::class, 'pages' => Page::class] as $label => $model) {
            $changed = 0;

            $model::whereNotNull('content')->lazyById()->each(function ($item) use ($sanitizer, $dryRun, &$changed) {
                $clean = $sanitizer->sanitize($item->content);
                if ($clean === $item->content) {
                    return;
                }

                $changed++;
                if (! $dryRun) {
                    $item->update(['content' => $clean]);
                }
            });

            $this->line(($dryRun ? "{$changed} {$label} would change." : "{$changed} {$label} sanitised."));
        }

        return self::SUCCESS;
    }
}
