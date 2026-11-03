<?php

namespace App\Http\Controllers;

use App\Blocks\BlockRegistry;
use App\Http\Requests\UpdateSiteSettingsRequest;
use App\Jobs\RebuildAstroJob;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\Revision;
use App\Models\SiteSetting;
use App\Rules\PageBackgroundColor;
use App\Services\PublicSiteBuilds;
use App\Services\PublicSiteSettings;
use App\Support\ThemeOptions;
use App\Traits\GeneratesUniqueSlug;
use App\Traits\HandlesPublishStatus;
use App\Traits\HandlesRevisions;
use App\Traits\PresentsIndexRows;
use App\Traits\SanitizesContent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class PageController extends Controller
{
    use GeneratesUniqueSlug, HandlesPublishStatus, HandlesRevisions, PresentsIndexRows, SanitizesContent;

    public function __construct(private BlockRegistry $blockRegistry) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Pages/Index', [
            // "can" tells the list which Edit/Delete buttons to show this user.
            'pages' => Page::with('author:id,name')->latest()->get()
                ->map(fn (Page $page) => $this->indexRow($request->user(), $page)),
        ]);
    }

    public function create(Request $request): Response
    {
        // New pages are block pages.
        return Inertia::render('Pages/Create', [
            'hasBlocks' => true,
            'blocks' => [],
            'frontPage' => $this->frontPageOption($request),
            'brandColor' => ThemeOptions::resolveBrand(SiteSetting::current()->brand_color),
        ]);
    }

    public function store(Request $request, PublicSiteSettings $site): RedirectResponse
    {
        $validated = $this->validatePage($request);
        $request->validate(['make_front_page' => ['sometimes', 'boolean']]);
        // Only admins choose the front page (the form shows editors no option).
        $makeFrontPage = $request->user()->isAdmin() && $request->boolean('make_front_page');
        $before = $makeFrontPage ? $site->payload() : null;

        $page = Page::create([
            ...$validated,
            ...$this->publishStatusAttributes($validated),
            'slug' => $this->uniqueSlug(Page::class, $validated['title']),
            'author_id' => $request->user()->id,
        ]);

        $message = $this->savedMessage($page, created: true);
        if ($makeFrontPage) {
            // One column, so the previous front page simply stops being it: it stays a
            // normal page at its own address. On a new site this saves the settings
            // row for the first time, with the defaults the site already showed.
            $settings = SiteSetting::current();
            $settings->front_page_id = $page->id;
            $settings->save();

            // The page observer queues a rebuild for a published page; a draft can
            // still change "/" (from the old front page to the latest posts).
            if ($site->payload() !== $before) {
                RebuildAstroJob::dispatch();
            }
            $message .= $page->status === 'published'
                ? ' It is now the site\'s home page.'
                : ' It is the site\'s home page once it is published; until then the home page shows the latest posts.';
        }

        // Back to the page itself: its "On the public site" panel shows when it is live.
        return redirect()->route('pages.edit', $page)->with('success', $message);
    }

    /**
     * The "Make this the home page" option on the new page form, for admins (the
     * front page is otherwise chosen under Settings, which only admins can use):
     * suggested (ticked) for a site's first page, and the current front page, to
     * say what it replaces. null for editors.
     */
    private function frontPageOption(Request $request): ?array
    {
        if (! $request->user()->isAdmin()) {
            return null;
        }
        $settings = SiteSetting::current();
        $current = $settings->front_page_id ? Page::query()->find($settings->front_page_id, ['id', 'title', 'slug']) : null;

        return [
            'suggest' => ! Page::query()->exists(),
            'current' => $current ? [
                'title' => $current->title,
                'slug' => $current->slug,
                'published' => Page::onPublicSite()->whereKey($current->id)->exists(),
            ] : null,
        ];
    }

    public function edit(Request $request, Page $page, PublicSiteBuilds $builds): Response
    {
        Gate::authorize('update', $page);

        return Inertia::render('Pages/Edit', [
            'page' => $page,
            'revisions' => $this->revisionList($page),
            // For a legacy page: its content as one text block, used if it is converted.
            'blocks' => $page->blocksOrLegacy(),
            'hasBlocks' => $page->hasBlocks(),
            // For the background colour's live contrast readout (PageBackgroundField).
            'brandColor' => ThemeOptions::resolveBrand(SiteSetting::current()->brand_color),
            // Whether the page is live yet (rebuilds take a minute or two), and where.
            'publicSite' => $builds->pageStatus($page),
            // The menu and the front page are on the Settings page, which only admins can use.
            'menu' => [
                'inMenu' => NavigationItem::where('page_id', $page->id)->exists(),
                'full' => NavigationItem::count() >= UpdateSiteSettingsRequest::MAX_NAVIGATION_ITEMS,
                'isFrontPage' => SiteSetting::current()->front_page_id === $page->id,
                'canEdit' => $request->user()->isAdmin(),
            ],
        ]);
    }

    public function update(Request $request, Page $page): RedirectResponse
    {
        Gate::authorize('update', $page);

        $validated = $this->validatePage($request);

        $oldSlug = $page->slug;
        $page->update([
            ...$validated,
            ...$this->publishStatusAttributes($validated, $page),
            'slug' => $this->uniqueSlug(Page::class, $validated['title'], $page->id),
        ]);

        return redirect()->route('pages.edit', $page)->with('success', $this->savedMessage($page, created: false, oldSlug: $oldSlug));
    }

    /** JSON for the page editor, polled while a rebuild is pending: PublicSiteBuilds::pageStatus(). */
    public function siteStatus(Page $page, PublicSiteBuilds $builds): JsonResponse
    {
        Gate::authorize('update', $page);

        return response()->json($builds->pageStatus($page));
    }

    /**
     * Admins: adds a published page to the end of the menu, from the page editor,
     * so nobody has to know to look under Settings. The order is changed there.
     * Checked like the Settings page's own list: published pages only, at most
     * MAX_NAVIGATION_ITEMS entries.
     */
    public function addToNavigation(Page $page): RedirectResponse
    {
        if (! Page::onPublicSite()->whereKey($page->id)->exists()) {
            return back()->with('warning', 'Only a published page can be in the menu. Publish it first, then add it.');
        }
        if (NavigationItem::where('page_id', $page->id)->exists()) {
            return back()->with('success', "\"{$page->title}\" is already in the menu.");
        }
        $max = UpdateSiteSettingsRequest::MAX_NAVIGATION_ITEMS;
        if (NavigationItem::count() >= $max) {
            return back()->with('warning', "The menu is full ({$max} links). Remove one under Settings, Navigation, then add this page.");
        }

        // Label null: the menu shows the page's title, and follows it when it changes.
        NavigationItem::create(['page_id' => $page->id, 'sort_order' => (int) NavigationItem::max('sort_order') + 1]);
        // The menu isn't page content, so the page observer doesn't queue this one.
        RebuildAstroJob::dispatch();

        return back()->with('success', "Added \"{$page->title}\" to the end of the menu. The public site shows it in a minute or two; change the order under Settings, Navigation.");
    }

    /**
     * After a save: what happened and the page's address on the public site. Whether
     * it is live yet is the editor's "On the public site" panel's job (a rebuild
     * takes a minute or two).
     */
    private function savedMessage(Page $page, bool $created, ?string $oldSlug = null): string
    {
        $path = '/'.$page->slug;
        $message = ($created ? "Page \"{$page->title}\" created" : "Changes to \"{$page->title}\" saved").match (true) {
            $page->status === 'published' => ". Its address is {$path}.",
            $page->publish_at !== null => ". It's scheduled: it goes on the public site, at {$path}, at the time you chose.",
            default => ". It's a draft, so it isn't on the public site. When it's published, its address is {$path}.",
        };

        // The slug follows the title, so renaming moves the page.
        if ($oldSlug !== null && $oldSlug !== $page->slug) {
            $message .= " The new title changed its address from /{$oldSlug}: the menu follows, but links to the old address elsewhere won't.";
        }

        return $message;
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
            // The slug comes from the title. One the public site uses for its own page
            // would save, show as published here, and never be built (Page::RESERVED_SLUGS).
            'title' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && ($message = Page::reservedSlugMessage($value)) !== null) {
                    $fail($message);
                }
            }],
            'content' => ['nullable', 'string'],
            ...$this->publishStatusRules(),
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:255'],
            // Full URL of the social sharing image, picked from the media library.
            'og_image' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            // Optional; without the key the stored colour is kept. Checked against the
            // brand colour the public site uses now, as the site checks it again.
            'background_color' => ['sometimes', 'nullable', new PageBackgroundColor(ThemeOptions::resolveBrand(SiteSetting::current()->brand_color))],
            ...($hasBlocks ? $this->blockRegistry->rules($blocks) : []),
        ], [], $hasBlocks ? $this->blockRegistry->attributes($blocks) : []);

        if ($hasBlocks) {
            $this->blockRegistry->validateUniqueIds($validator, $blocks);
        }

        $validated = $this->sanitizeContent($validator->validate());
        unset($validated['blocks']);
        if (array_key_exists('background_color', $validated)) {
            // Stored uppercase, like the brand colour.
            $validated['background_color'] = $validated['background_color'] === null ? null : strtoupper($validated['background_color']);
        }

        if ($hasBlocks) {
            $validated['blocks'] = $this->blockRegistry->normalize($blocks);
        }

        return $validated;
    }
}
