<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use App\Rules\ImageUpload;
use App\Services\SvgSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Media/Index', [
            // Everything in the library: direct uploads and imported featured images.
            'media' => $this->library()->get()->map(fn (Media $media) => $this->present($media)),
        ]);
    }

    /** JSON list for the OG image picker in the post/page editor. */
    public function list(): JsonResponse
    {
        return response()->json(
            $this->library()->get()->map(fn (Media $media) => $this->present($media))
        );
    }

    /**
     * All media except the site logo, which belongs to the Settings page: deleting
     * it here would bypass the rebuild, and its 128px conversion isn't an OG image.
     */
    private function library(): Builder
    {
        return Media::query()->where('model_type', '!=', (new SiteSetting)->getMorphClass())->latest();
    }

    /**
     * Returns JSON for plain API callers (e.g. a future TipTap image upload),
     * and redirects back for Inertia form submissions from the Media page,
     * since Inertia requests must receive an Inertia/redirect response.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', new ImageUpload],
        ]);

        $file = $request->file('file');
        $type = ImageUpload::detectType($file);

        // An SVG is stored as cleaned by the sanitiser, never as uploaded.
        $adder = $type === 'image/svg+xml'
            ? $request->user()->addMediaFromString($this->cleanSvg($file))
            : $request->user()->addMedia($file);

        // The stored name is chosen here, with the extension of the detected type,
        // never the uploaded one: "x.html" holding a GIF can't be stored as x.html.
        $media = $adder
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->usingFileName(ImageUpload::fileName($file, $type))
            ->toMediaCollection('library');

        if ($request->wantsJson()) {
            return response()->json(['media' => $this->present($media)], 201);
        }

        return back();
    }

    public function destroy(Request $request, Media $media): JsonResponse|RedirectResponse
    {
        // Deleting the model also removes the original file and all conversions from disk.
        $media->delete();

        if ($request->wantsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    private function cleanSvg(UploadedFile $file): string
    {
        $clean = app(SvgSanitizer::class)->sanitize((string) file_get_contents($file->getRealPath()));

        if ($clean === null) {
            throw ValidationException::withMessages(['file' => 'The file could not be read as an SVG image.']);
        }

        return $clean;
    }

    private function present(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->name,
            'file_name' => $media->file_name,
            // Spatie keeps the uploaded name (sanitised) as file_name.
            'original_filename' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'human_size' => $media->human_readable_size,
            'collection' => $media->collection_name,
            'url' => $media->getUrl(),
            // SVGs and not-yet-processed images have no thumb; fall back to the original.
            'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
            'webp_url' => $media->hasGeneratedConversion('webp') ? $media->getUrl('webp') : null,
            'medium_url' => $media->hasGeneratedConversion('medium') ? $media->getUrl('medium') : null,
            // 1200x630 JPEG for social sharing; null for SVGs and media uploaded before it existed.
            'og_url' => $media->hasGeneratedConversion('og') ? $media->getUrl('og') : null,
            'created_at' => $media->created_at,
        ];
    }
}
