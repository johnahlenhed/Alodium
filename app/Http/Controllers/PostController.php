<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Revision;
use App\Traits\GeneratesUniqueSlug;
use App\Traits\HandlesPublishStatus;
use App\Traits\HandlesRevisions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    use GeneratesUniqueSlug, HandlesPublishStatus, HandlesRevisions;

    public function index(): Response
    {
        return Inertia::render('Posts/Index', [
            'posts' => Post::with('author:id,name')->latest()->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Posts/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePost($request);

        Post::create([
            ...$validated,
            ...$this->publishStatusAttributes($validated),
            'slug' => $this->uniqueSlug(Post::class, $validated['title']),
            'author_id' => $request->user()->id,
        ]);

        return redirect()->route('posts.index');
    }

    public function edit(Post $post): Response
    {
        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'revisions' => $this->revisionList($post),
        ]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        $validated = $this->validatePost($request);

        $post->update([
            ...$validated,
            ...$this->publishStatusAttributes($validated, $post),
            'slug' => $this->uniqueSlug(Post::class, $validated['title'], $post->id),
        ]);

        return redirect()->route('posts.index');
    }

    public function revisions(Post $post): JsonResponse
    {
        return response()->json($this->revisionList($post));
    }

    public function restoreRevision(Post $post, Revision $revision): RedirectResponse
    {
        return $this->restore($post, $revision, 'posts.edit');
    }

    public function destroy(Post $post): RedirectResponse
    {
        $post->delete();

        return redirect()->route('posts.index');
    }

    private function validatePost(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            ...$this->publishStatusRules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            // Full URL of the social sharing image, picked from the media library.
            'og_image' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ]);
    }
}
