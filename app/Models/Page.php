<?php

namespace App\Models;

use App\Blocks\BlockRegistry;
use App\Models\Concerns\RegistersImageConversions;
use App\Observers\PublicContentObserver;
use App\Support\PlainText;
use App\Traits\HasRevisions;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[ObservedBy(PublicContentObserver::class)]
class Page extends Model implements HasMedia
{
    /**
     * Slugs the public site uses for its own routes; a CMS page with one of them
     * is never built (RESERVED in astro/src/pages/[slug].astro, checked by
     * SiteThemeSyncTest). Such a page can't be in the navigation or the front page,
     * so the page form refuses a title that would get one (reservedSlugMessage())
     * and the WordPress import skips such pages.
     *
     * These are all the top-level routes of astro/src/pages. Files there and in
     * astro/public (robots.txt, favicon.svg, _astro/, ...) can't clash: a slug
     * from Str::slug() has no dots or underscores. A folder in astro/public, such
     * as fonts/, doesn't clash either: the page is built as fonts/index.html next
     * to the files.
     */
    public const RESERVED_SLUGS = ['blog', 'contact', '404', 'index'];

    use HasRevisions, InteractsWithMedia, RegistersImageConversions {
        // Both traits define registerMediaConversions(); ours holds the real conversions.
        RegistersImageConversions::registerMediaConversions insteadof InteractsWithMedia;
    }

    protected $fillable = [
        'author_id',
        'title',
        'slug',
        'content',
        'blocks',
        'meta_title',
        'meta_description',
        'og_image',
        'status',
        'published_at',
        'publish_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'publish_at' => 'datetime',
        // { version, blocks: [...] } or null for a legacy (HTML content) page.
        'blocks' => 'array',
        // Set on the Settings page only (not fillable, not part of revisions).
        'show_in_nav' => 'boolean',
        'nav_order' => 'integer',
    ];

    /**
     * Pages the public site shows: published and not scheduled for the future
     * (the same rule as PublicContentController::published()), with a slug the
     * site can build.
     */
    public function scopeOnPublicSite(Builder $query): void
    {
        $query->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->whereNotIn('slug', self::RESERVED_SLUGS);
    }

    /** What the public site shows at /$slug instead of a CMS page, or null if the slug is free. */
    public static function reservedSlugOwner(string $slug): ?string
    {
        return match ($slug) {
            'blog' => "the site's built-in blog (the list of posts)",
            'contact' => "the site's built-in contact page",
            '404' => "the site's \"page not found\" page",
            'index' => "the site's home page",
            // A slug added to RESERVED_SLUGS without a description here.
            default => in_array($slug, self::RESERVED_SLUGS, true) ? 'a page the site builds itself' : null,
        };
    }

    /**
     * Why a page can't have this title, or null if it can. The slug comes from the
     * title (GeneratesUniqueSlug), so the title is what has to change.
     */
    public static function reservedSlugMessage(string $title): ?string
    {
        $slug = Str::slug($title) ?: 'untitled';
        $owner = self::reservedSlugOwner($slug);
        if ($owner === null) {
            return null;
        }

        $hint = match ($slug) {
            'contact' => ', such as "Contact us". The built-in contact page is at /contact, and Settings can put it in the menu',
            'blog' => ', such as "Our blog". The built-in blog is at /blog, and Settings can put it in the menu',
            'index' => ', such as "Home". To choose which page the home page shows, use Front page under Settings',
            default => '',
        };

        return "A page titled \"{$title}\" would get the address /{$slug}, which is {$owner}, so it would never be shown. Choose another title{$hint}.";
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * A block page stores its content in `blocks`; a legacy page (blocks = null)
     * uses `content` (HTML). An empty block list still counts as a block page.
     */
    public function hasBlocks(): bool
    {
        return $this->blocks !== null;
    }

    /** The stored block list ([] for a legacy page). */
    public function blockList(): array
    {
        return $this->blocks['blocks'] ?? [];
    }

    /**
     * The block list, or for a legacy page one text block holding `content`
     * (none if content is empty). Used to convert a legacy page in the editor.
     */
    public function blocksOrLegacy(): array
    {
        if ($this->hasBlocks()) {
            return $this->blockList();
        }

        if (trim((string) $this->content) === '') {
            return [];
        }

        return [[
            'id' => (string) Str::uuid(),
            'type' => 'text',
            'data' => ['html' => $this->content],
        ]];
    }

    /** Readable text: all block text for block pages, stripped `content` for legacy pages. */
    public function plainText(): string
    {
        return $this->hasBlocks()
            ? app(BlockRegistry::class)->plainText($this->blockList())
            : PlainText::fromHtml($this->content);
    }

    /**
     * Image URLs from the blocks, in the order used for the social sharing image:
     * the hero image, the first image block, the first column image.
     */
    public function blockImages(): array
    {
        $blocks = collect($this->blockList());
        $firstOf = fn (string $type) => $blocks->first(fn ($block) => ($block['type'] ?? null) === $type);

        return array_values(array_filter([
            $firstOf('hero')['data']['image'] ?? null,
            $firstOf('image')['data']['image'] ?? null,
            $blocks->where('type', 'columns')->flatMap(fn ($block) => $block['data']['columns'] ?? [])->pluck('image')->filter()->first(),
        ]));
    }

    /** HTML inside the blocks (text and columns), e.g. to find an image in it. */
    public function blockHtml(): string
    {
        return collect($this->blockList())->map(fn ($block) => match ($block['type'] ?? null) {
            'text' => $block['data']['html'] ?? '',
            'columns' => collect($block['data']['columns'] ?? [])->pluck('html')->implode(''),
            default => '',
        })->implode('');
    }

    public function registerMediaCollections(): void
    {
        // One featured image per page; adding a new one replaces the old.
        $this->addMediaCollection('featured')->singleFile();
    }
}
