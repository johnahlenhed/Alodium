<?php

namespace App\Support;

use App\Blocks\BlockRegistry;
use App\Models\Page;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * The privacy notice every new site starts with: a normal block page at
 * /privacy, created as a draft when the site gets its first admin
 * (user:set-password on a fresh install; DatabaseSeeder locally). It is
 * content, not code: the site owner edits it in the block editor, adds the
 * contact details and publishes it. The public site's footer links to it once
 * it is published (PublicSiteSettings: privacy_page). Renaming the page moves
 * its address, as with any page, and the footer then shows the sentence
 * without the link.
 *
 * Existing sites never get it automatically: nothing here runs on a site that
 * already has users, and a page already at /privacy is never touched.
 */
final class PrivacyNotice
{
    public const SLUG = 'privacy';

    /**
     * A page's address follows its title on every save (GeneratesUniqueSlug), so
     * the title is the one that gives /privacy: "Privacy notice" would move the
     * page to /privacy-notice the first time it is saved in the editor.
     */
    public const TITLE = 'Privacy';

    /**
     * Left as written, so the page can't be published unfilled without anyone
     * noticing: while it is in the notice, the page can be saved as a draft but
     * not published or scheduled (PageController::validatePage()).
     */
    public const CONTACT_PLACEHOLDER = '[Add your contact details here before publishing.]';

    public const PUBLISH_BLOCKED = 'The privacy notice still says "'.self::CONTACT_PLACEHOLDER.'". Replace that line with your contact details before publishing it. Until then you can save it as a draft.';

    /**
     * Creates the draft notice by $author, or returns null when a page already
     * has the slug. Throws when the retention period can't be read: the notice
     * states it, so it is never guessed.
     */
    public static function createDraft(User $author): ?Page
    {
        if (Page::where('slug', self::SLUG)->exists()) {
            return null;
        }

        $registry = app(BlockRegistry::class);
        $blocks = [['type' => 'text', 'data' => ['html' => self::html(self::retentionDays()), 'variant' => 'default']]];
        // The same rules and normalisation as a page saved in the editor.
        Validator::make(['blocks' => $blocks], $registry->rules($blocks))->validate();

        return Page::create([
            'author_id' => $author->id,
            'title' => self::TITLE,
            'slug' => self::SLUG,
            'content' => '',
            'blocks' => $registry->normalize($blocks),
            'meta_description' => 'What this site records about visits, what it doesn\'t, and how long it keeps it.',
            'status' => 'draft',
        ]);
    }

    /** ['slug' => 'privacy'] while the notice is on the public site, otherwise null. */
    public static function publicLink(): ?array
    {
        return Page::onPublicSite()->where('slug', self::SLUG)->exists() ? ['slug' => self::SLUG] : null;
    }

    /** Whether any of the given content (block lists, HTML) still holds the contact placeholder. */
    public static function containsPlaceholder(mixed ...$contents): bool
    {
        foreach ($contents as $content) {
            $text = is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($text) && str_contains($text, self::CONTACT_PLACEHOLDER)) {
                return true;
            }
        }

        return false;
    }

    /** config/analytics.php (ANALYTICS_RETENTION_DAYS), as analytics:prune reads it. */
    private static function retentionDays(): int
    {
        $days = config('analytics.retention_days');
        if (! is_int($days) || $days < 1) {
            throw new RuntimeException('analytics.retention_days (ANALYTICS_RETENTION_DAYS) is not a whole number of days, so the privacy notice was not created.');
        }

        return $days;
    }

    private static function html(int $retentionDays): string
    {
        $sections = [
            'What we collect' => [
                'Each page view records: the page you viewed, the page that linked you here (if any), the country you’re visiting from, your browser’s language setting, the width of your browser window, how long you stayed, and how far you scrolled. If you arrived through a tracked marketing link, we also record its campaign name, source and medium. If you click a link away from this site, we record the destination address.',
            ],
            'What we don’t collect' => [
                'We don’t set cookies or store anything in your browser. Nothing we record can link two separate visits back to the same person — there’s no profile that follows you from one visit to the next. We don’t use your device’s characteristics (screen details, fonts, etc.) to identify you. Your IP address is never written to our visit records.',
            ],
            'IP address' => [
                'Your IP is used for a few seconds, in memory, to work out which country you’re visiting from, then discarded. A short-lived technical cache may briefly hold a one-way, salted representation of it (rotated daily) purely to avoid repeating that lookup; this cannot be converted back into your IP address without our server’s private key. Separately, like almost every website, our web server keeps its own short-term technical access logs for security purposes, which do include IP addresses — these aren’t part of our analytics and are kept only briefly.',
            ],
            'Retention' => [
                "Visit records are deleted automatically after {$retentionDays} days. Backups, kept for disaster recovery only, may retain recently-deleted data for up to 30 additional days. If this site’s backups are stored off-server, encrypted copies may also be held by our backup storage provider.",
            ],
            'Third parties' => [
                'We don’t sell this data or share it with advertising networks. Our error-monitoring tool is configured to never receive analytics traffic.',
            ],
            'Questions' => [self::CONTACT_PLACEHOLDER],
        ];

        $html = '';
        foreach ($sections as $heading => $paragraphs) {
            $html .= '<h2>'.e($heading).'</h2>';
            foreach ($paragraphs as $paragraph) {
                $html .= '<p>'.e($paragraph).'</p>';
            }
        }

        return $html;
    }
}
