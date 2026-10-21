<?php

namespace App\Http\Controllers;

use App\Mail\ContactSubmissionReceived;
use App\Models\Page;
use App\Models\Post;
use App\Models\Submission;
use App\Support\PlainText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only content API for the Astro public site, which calls it at build time.
 * Only published content is ever exposed; drafts and scheduled items 404.
 */
class PublicContentController extends Controller
{
    public function posts(): JsonResponse
    {
        return response()->json($this->listing(Post::query()));
    }

    public function post(string $slug): JsonResponse
    {
        return $this->single(Post::query(), $slug);
    }

    public function pages(): JsonResponse
    {
        return response()->json($this->listing(Page::query()));
    }

    public function page(string $slug): JsonResponse
    {
        return $this->single(Page::query(), $slug);
    }

    public function contact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
            // Honeypot: a field hidden from people. Bots that fill every input reveal themselves.
            'website' => ['nullable', 'string', 'max:255'],
        ]);

        // Pretend success so the bot learns nothing, but store and send nothing.
        if (! empty($validated['website'])) {
            return response()->json(['success' => true]);
        }

        $submission = Submission::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'message' => $validated['message'],
        ]);

        // The submission is already saved (and visible in the admin), so a mail
        // outage must not turn into an error for the visitor.
        try {
            Mail::to(config('mail.from.address'))->send(new ContactSubmissionReceived($submission));
        } catch (Throwable $e) {
            Log::error('Contact notification email failed', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);
        }

        return response()->json(['success' => true]);
    }

    /** Published and not scheduled for the future. */
    private function published(Builder $query): Builder
    {
        return $query
            ->where('status', 'published')
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

    private function listing(Builder $query): array
    {
        return $this->published($query)
            ->with('author:id,name')
            ->orderByDesc('published_at')
            ->get()
            ->map(fn (Model $item) => [
                'id' => $item->id,
                'title' => $item->title,
                'slug' => $item->slug,
                'excerpt' => $this->excerpt($item),
                ...$this->blocks($item),
                'meta_title' => $item->meta_title,
                'meta_description' => $item->meta_description,
                'published_at' => $item->published_at?->toIso8601String(),
                'updated_at' => $item->updated_at?->toIso8601String(),
                'author' => $item->author?->name,
            ])
            ->all();
    }

    private function single(Builder $query, string $slug): JsonResponse
    {
        $item = $this->published($query)->with('author:id,name')->where('slug', $slug)->first();

        if (! $item) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'id' => $item->id,
            'title' => $item->title,
            'slug' => $item->slug,
            // A block page's old HTML content (from before a conversion) is never exposed.
            'content' => $this->isBlockPage($item) ? '' : $item->content,
            ...$this->blocks($item),
            'meta_title' => $item->meta_title,
            'meta_description' => $item->meta_description,
            ...$this->ogImage($item),
            'published_at' => $item->published_at?->toIso8601String(),
            // For dateModified in the public site's JSON-LD.
            'updated_at' => $item->updated_at?->toIso8601String(),
            'author' => $item->author?->name,
        ]);
    }

    private function isBlockPage(Model $item): bool
    {
        return $item instanceof Page && $item->hasBlocks();
    }

    /** Pages only: the block list, or null for a legacy page. Posts have no blocks. */
    private function blocks(Model $item): array
    {
        return $item instanceof Page ? ['blocks' => $item->hasBlocks() ? $item->blockList() : null] : [];
    }

    /**
     * Image for social sharing, in order of preference:
     * 1. the OG image chosen in the editor (the 1200x630 "og" conversion),
     * 2. block pages only: the hero image, the first image block, the first column image,
     * 3. the featured image (e.g. from the WordPress import),
     * 4. the first image in the content (for block pages: in the text and column HTML).
     * Width/height are only given for 1, the only case where the size is known.
     */
    private function ogImage(Model $item): array
    {
        if ($item->og_image) {
            return ['og_image' => $item->og_image, 'og_image_width' => 1200, 'og_image_height' => 630];
        }

        $blockPage = $this->isBlockPage($item);

        foreach ($blockPage ? $item->blockImages() : [] as $image) {
            if ($url = $this->absoluteUrl($image)) {
                return ['og_image' => $url, 'og_image_width' => null, 'og_image_height' => null];
            }
        }

        $featured = $item->getFirstMedia('featured');
        $url = $featured
            ? $featured->getUrl($featured->hasGeneratedConversion('og') ? 'og' : '')
            : $this->firstContentImage($blockPage ? $item->blockHtml() : $item->content);

        return ['og_image' => $url, 'og_image_width' => null, 'og_image_height' => null];
    }

    /** src of the first <img> in the HTML, made absolute (social platforms need full URLs). */
    private function firstContentImage(?string $html): ?string
    {
        if (! $html || ! preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $html, $match)) {
            return null;
        }

        return $this->absoluteUrl(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
    }

    private function absoluteUrl(string $src): ?string
    {
        return match (true) {
            str_starts_with($src, 'http://'), str_starts_with($src, 'https://') => $src,
            str_starts_with($src, '//') => 'https:'.$src,
            str_starts_with($src, '/') => rtrim(config('app.url'), '/').$src,
            default => null, // data: URIs and other relative paths can't be shared
        };
    }

    /**
     * First 160 characters as plain text, with spaces kept between paragraphs and
     * blocks. Pages use plainText() (block text, or stripped content for legacy pages).
     */
    private function excerpt(Model $item): string
    {
        $text = $item instanceof Page ? $item->plainText() : PlainText::fromHtml($item->content);

        return Str::limit($text, 160);
    }
}
