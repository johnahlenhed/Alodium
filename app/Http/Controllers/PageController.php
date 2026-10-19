<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Traits\GeneratesUniqueSlug;
use App\Traits\HandlesPublishStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    use GeneratesUniqueSlug, HandlesPublishStatus;

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
            ...$this->publishStatusAttributes($validated),
            'slug' => $this->uniqueSlug(Page::class, $validated['title']),
            'author_id' => $request->user()->id,
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

        $page->update([
            ...$validated,
            ...$this->publishStatusAttributes($validated, $page),
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
            ...$this->publishStatusRules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
