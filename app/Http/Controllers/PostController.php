<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\Revision;
use App\Traits\GeneratesUniqueSlug;
use App\Traits\HandlesPublishStatus;
use App\Traits\HandlesRevisions;
use App\Traits\SanitizesContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    use GeneratesUniqueSlug, HandlesPublishStatus, HandlesRevisions, SanitizesContent;

    public function index(Request $request): Response
    {
        return Inertia::render('Posts/Index', [
            // "can" tells the list which Edit/Delete buttons to show this user.
            'posts' => Post::with('author:id,name')->latest()->get()->map(fn (Post $post) => [
                ...$post->toArray(),
                'can' => [
                    'update' => $request->user()->can('update', $post),
                    'delete' => $request->user()->can('delete', $post),
                ],
            ]),
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
        Gate::authorize('update', $post);

        return Inertia::render('Posts/Edit', [
            'post' => $post,
            'revisions' => $this->revisionList($post),
        ]);
    }

    public function update(Request $request, Post $post): RedirectResponse
    {
        Gate::authorize('update', $post);

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
        Gate::authorize('update', $post);

        return response()->json($this->revisionList($post));
    }

    public function restoreRevision(Post $post, Revision $revision): RedirectResponse
    {
        Gate::authorize('update', $post);

        return $this->restore($post, $revision, 'posts.edit');
    }

    public function destroy(Post $post): RedirectResponse
    {
        Gate::authorize('delete', $post);

        $post->delete();

        return redirect()->route('posts.index');
    }

    private function validatePost(Request $request): array
    {
        return $this->sanitizeContent($request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            ...$this->publishStatusRules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            // Full URL of the social sharing image, picked from the media library.
            'og_image' => ['nullable', 'string', 'max:2048', 'url:http,https'],
        ]));
    }
}
