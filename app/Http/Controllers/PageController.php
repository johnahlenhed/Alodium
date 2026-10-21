<?php

namespace App\Http\Controllers;

use App\Blocks\BlockRegistry;
use App\Models\Page;
use App\Models\Revision;
use App\Traits\GeneratesUniqueSlug;
use App\Traits\HandlesPublishStatus;
use App\Traits\HandlesRevisions;
use App\Traits\SanitizesContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    use GeneratesUniqueSlug, HandlesPublishStatus, HandlesRevisions, SanitizesContent;

    public function __construct(private BlockRegistry $blockRegistry) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Pages/Index', [
            // "can" tells the list which Edit/Delete buttons to show this user.
            'pages' => Page::with('author:id,name')->latest()->get()->map(fn (Page $page) => [
                ...$page->toArray(),
                'can' => [
                    'update' => $request->user()->can('update', $page),
                    'delete' => $request->user()->can('delete', $page),
                ],
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Pages/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validatePage($request);

        Page::create([
            ...$validated,
            ...$this->publishStatusAttributes($validated),
            'slug' => $this->uniqueSlug(Page::class, $validated['title']),
            'author_id' => $request->user()->id,
        ]);

        return redirect()->route('pages.index');
    }

    public function edit(Page $page): Response
    {
        Gate::authorize('update', $page);

        return Inertia::render('Pages/Edit', [
            'page' => $page,
            'revisions' => $this->revisionList($page),
        ]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $validated = $this->validatePage($request);

        $page->update([
            ...$validated,
            ...$this->publishStatusAttributes($validated, $page),
            'slug' => $this->uniqueSlug(Page::class, $validated['title'], $page->id),
        ]);

        return redirect()->route('pages.index');
    }

    public function revisions(Page $page): JsonResponse
    {
        Gate::authorize('update', $page);

        return response()->json($this->revisionList($page));
    }

    public function restoreRevision(Page $page, Revision $revision): RedirectResponse
    {
        Gate::authorize('update', $page);

        return $this->restore($page, $revision, 'pages.edit');
    }

    public function destroy(Page $page): RedirectResponse
    {
        Gate::authorize('delete', $page);

        $page->delete();

        return redirect()->route('pages.index');
    }

    /**
     * `blocks` is optional. Without the key (as the current page form sends it) the
     * stored blocks are left alone; null turns the page into a legacy page; a list
     * is validated per block type and stored in the BlockRegistry shape.
     */
    private function validatePage(Request $request): array
    {
        $hasBlocks = $request->exists('blocks');
        $blocks = $request->input('blocks');

        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            ...$this->publishStatusRules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            // Full URL of the social sharing image, picked from the media library.
            'og_image' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            ...($hasBlocks ? $this->blockRegistry->rules($blocks) : []),
        ]);

        if ($hasBlocks) {
            $this->blockRegistry->validateUniqueIds($validator, $blocks);
        }

        $validated = $this->sanitizeContent($validator->validate());
        unset($validated['blocks']);

        if ($hasBlocks) {
            $validated['blocks'] = $this->blockRegistry->normalize($blocks);
        }

        return $validated;
    }
}
