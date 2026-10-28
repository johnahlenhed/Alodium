<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSiteSettingsRequest;
use App\Jobs\RebuildAstroJob;
use App\Models\NavigationItem;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Services\PublicSiteSettings;
use App\Support\ThemeOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/** The admin-only Settings page: the public site's identity, look, navigation and front page. */
class SiteSettingsController extends Controller
{
    public function edit(): Response
    {
        $settings = SiteSetting::current();

        // Only pages on the public site can be chosen; ordered by title for the page list.
        $pages = Page::onPublicSite()->orderBy('title')->get(['id', 'title', 'slug']);
        // A page entry whose page is off the public site is hidden there, and isn't
        // offered here either: saving drops it (as the old show_in_nav flag was cleared).
        $navigation = NavigationItem::query()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (NavigationItem $item) => $item->page_id === null || $pages->contains('id', $item->page_id))
            ->map(fn (NavigationItem $item) => $item->only(['page_id', 'url', 'label']))
            ->values();

        // A front page that was unpublished or deleted is shown as "Latest posts",
        // which is also what the public site falls back to.
        $frontPageMissing = $settings->front_page_id !== null && ! $pages->contains('id', $settings->front_page_id);

        return Inertia::render('Settings/Edit', [
            'settings' => [
                // The old Blog and Contact switches are navigation entries now (navigation_items).
                ...Arr::except(Arr::only($settings->attributesToArray(), $settings->getFillable()), ['show_blog_in_nav', 'show_contact_in_nav']),
                'front_page_id' => $frontPageMissing ? null : $settings->front_page_id,
                'navigation' => $navigation,
            ],
            'frontPageMissing' => $frontPageMissing,
            'logo' => $this->logoPreview($settings->logo()),
            'pages' => $pages->map(fn (Page $page) => ['id' => $page->id, 'title' => $page->title, 'slug' => $page->slug])->values(),
            'options' => [
                'fonts' => $this->options(ThemeOptions::FONTS),
                'corners' => collect(ThemeOptions::CORNERS)->map(fn (array $corner, string $value) => ['value' => $value, ...$corner])->values(),
                'buttons' => $this->options(ThemeOptions::BUTTONS),
                'minContrast' => ThemeOptions::MIN_BRAND_CONTRAST,
                'maxNavigation' => UpdateSiteSettingsRequest::MAX_NAVIGATION_ITEMS,
            ],
        ]);
    }

    public function update(UpdateSiteSettingsRequest $request, PublicSiteSettings $site): RedirectResponse
    {
        $validated = $request->validated();

        // What the public site sees before the save, to know whether it needs a rebuild.
        $before = $site->payload();

        $settings = DB::transaction(function () use ($validated) {
            $settings = SiteSetting::current();
            $settings->fill([
                ...Arr::only($validated, $settings->getFillable()),
                'brand_color' => strtoupper($validated['brand_color']),
            ]);
            $settings->save();

            $this->saveNavigation($validated['navigation'] ?? []);

            return $settings;
        });

        // After the transaction: files can't be rolled back, so they are written last.
        if ($request->hasFile('logo')) {
            $this->saveLogo($settings, $request->file('logo'));
        } elseif ($request->boolean('remove_logo')) {
            $settings->clearMediaCollection('logo');
        }

        // Only a change the public site can show is worth a rebuild.
        $changed = $site->payload() !== $before;
        if ($changed) {
            RebuildAstroJob::dispatch();
        }

        return redirect()->route('settings.edit')->with('success', $changed
            ? 'Saved. The public site updates in a minute or two.'
            : 'Saved. Nothing changed, so the public site stays as it is.');
    }

    /**
     * Replaces the navigation with the entries given, in that order. Nothing else
     * refers to the entries, so a fresh list is simpler than matching up rows. The
     * pages themselves aren't touched (their updated_at is their dateModified on
     * the public site). update() decides about the rebuild.
     */
    private function saveNavigation(array $items): void
    {
        NavigationItem::query()->delete();

        // By index, not array order: validated() adds the entries rule by rule (every
        // entry with a page_id before the ones with only a url), so the keys are right
        // but their order isn't.
        ksort($items);
        foreach (array_values($items) as $position => $item) {
            $pageId = isset($item['page_id']) ? (int) $item['page_id'] : null;
            NavigationItem::create([
                'page_id' => $pageId,
                'url' => $pageId === null ? $item['url'] : null,
                'label' => $item['label'] ?? null,
                'sort_order' => $position + 1,
            ]);
        }
    }

    private function saveLogo(SiteSetting $settings, UploadedFile $file): void
    {
        // The type from the content (the form request allowed only these), not the uploaded name.
        $extension = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$file->getMimeType()];

        // singleFile(): the previous logo and its conversion are deleted.
        $media = $settings->addMedia($file)
            ->usingName('Logo')
            ->usingFileName("logo.$extension")
            ->toMediaCollection('logo');

        // The conversion is made during the upload; its size goes into the API (width/height attributes).
        $size = is_file($media->getPath('logo')) ? getimagesize($media->getPath('logo')) : false;
        if ($size) {
            $media->setCustomProperty('logo_width', $size[0])->setCustomProperty('logo_height', $size[1])->save();
        }
    }

    private function logoPreview(?Media $media): ?array
    {
        if (! $media || ! $media->hasGeneratedConversion('logo')) {
            return null;
        }

        return [
            'url' => $media->getUrl('logo'),
            'width' => (int) $media->getCustomProperty('logo_width'),
            'height' => (int) $media->getCustomProperty('logo_height'),
        ];
    }

    /** ['inter' => 'Inter'] -> [['value' => 'inter', 'label' => 'Inter']] */
    private function options(array $options): array
    {
        return collect($options)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}
