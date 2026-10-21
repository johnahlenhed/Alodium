<?php

namespace App\Models;

use App\Blocks\BlockRegistry;
use App\Models\Concerns\RegistersImageConversions;
use App\Observers\PublicContentObserver;
use App\Support\PlainText;
use App\Traits\HasRevisions;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[ObservedBy(PublicContentObserver::class)]
class Page extends Model implements HasMedia
{
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
    ];

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
