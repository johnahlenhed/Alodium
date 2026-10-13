<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\GeneratesUniqueSlug;
use App\Models\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    use GeneratesUniqueSlug;

    public function index(): Response
    {
        return Inertia::render('Pages/Index', [
            'pages' => Page::with('author:id,name')->latest()->get(),
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
            'slug' => $this->uniqueSlug(Page::class, $validated['title']),
            'author_id' => $request->user()->id,
            'published_at' => $validated['status'] === 'published' ? now() : null,
        ]);

        return redirect()->route('pages.index');
    }

    public function edit(Page $page): Response
    {
        return Inertia::render('Pages/Edit', [
            'page' => $page,
        ]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        $validated = $this->validatePage($request);

        // Only stamp published_at the first time the page becomes published,
        // so re-saving or toggling to draft and back keeps the original date.
        if ($validated['status'] === 'published' && $page->published_at === null) {
            $validated['published_at'] = now();
        }

        $page->update([
            ...$validated,
            'slug' => $this->uniqueSlug(Page::class, $validated['title'], $page->id),
        ]);

        return redirect()->route('pages.index');
    }

    public function destroy(Page $page): RedirectResponse
    {
        $page->delete();

        return redirect()->route('pages.index');
    }

    private function validatePage(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
