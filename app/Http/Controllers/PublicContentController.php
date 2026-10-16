<?php

namespace App\Http\Controllers;

use App\Mail\ContactSubmissionReceived;
use App\Models\Page;
use App\Models\Post;
use App\Models\Submission;
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
                'excerpt' => $this->excerpt($item->content),
                'meta_title' => $item->meta_title,
                'meta_description' => $item->meta_description,
                'published_at' => $item->published_at?->toIso8601String(),
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
            'content' => $item->content,
            'meta_title' => $item->meta_title,
            'meta_description' => $item->meta_description,
            // Fall back to the featured image (e.g. from the WordPress import) for social previews.
            'og_image' => $item->og_image ?: ($item->getFirstMediaUrl('featured') ?: null),
            'published_at' => $item->published_at?->toIso8601String(),
            'author' => $item->author?->name,
        ]);
    }

    /** First 160 characters of the content as plain text. */
    private function excerpt(?string $html): string
    {
        // Add a space at block boundaries first, so "</p><p>" doesn't glue words together.
        $text = strip_tags(preg_replace('/<(\/p|br\s*\/?|\/h[1-6]|\/li|\/blockquote)>/i', '$0 ', $html ?? '') ?? '');
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)) ?? '');

        return Str::limit($text, 160);
    }
}
