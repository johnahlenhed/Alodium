<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSiteSettingsRequest;
use App\Jobs\RebuildAstroJob;
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

        // Only pages on the public site can be chosen; ordered by title for the checkbox list.
        $pages = Page::onPublicSite()->orderBy('title')->get(['id', 'title', 'slug', 'show_in_nav', 'nav_order']);
        $navigation = $pages->where('show_in_nav', true)->sortBy([['nav_order', 'asc'], ['id', 'asc']])->pluck('id')->values();

        // A front page that was unpublished or deleted is shown as "Latest posts",
        // which is also what the public site falls back to.
        $frontPageMissing = $settings->front_page_id !== null && ! $pages->contains('id', $settings->front_page_id);

        return Inertia::render('Settings/Edit', [
            'settings' => [
                ...Arr::only($settings->attributesToArray(), $settings->getFillable()),
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
                'maxNavigation' => UpdateSiteSettingsRequest::MAX_NAVIGATION_PAGES,
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
     * The navigation in the order given; every other page leaves it. Written with
     * the query builder: these columns aren't content, so the pages' updated_at
     * (their dateModified on the public site) and revisions stay untouched, and
     * the content observer doesn't fire. update() decides about the rebuild.
     */
    private function saveNavigation(array $ids): void
    {
        $ids = array_values(array_map('intval', $ids));

        Page::query()->where('show_in_nav', true)->whereNotIn('id', $ids)->toBase()->update(['show_in_nav' => false, 'nav_order' => 0]);

        foreach ($ids as $position => $id) {
            Page::query()->whereKey($id)->toBase()->update(['show_in_nav' => true, 'nav_order' => $position + 1]);
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
