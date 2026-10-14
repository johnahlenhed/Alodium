<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MediaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Media/Index', [
            // Everything in the library: direct uploads and imported featured images.
            'media' => Media::latest()->get()->map(fn (Media $media) => $this->present($media)),
        ]);
    }

    /**
     * Returns JSON for plain API callers (e.g. a future TipTap image upload),
     * and redirects back for Inertia form submissions from the Media page,
     * since Inertia requests must receive an Inertia/redirect response.
     */
    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,svg'],
        ]);

        $media = $request->user()
            ->addMediaFromRequest('file')
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

    private function present(Media $media): array
    {
        return [
            'id' => $media->id,
            'name' => $media->name,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'human_size' => $media->human_readable_size,
            'collection' => $media->collection_name,
            'url' => $media->getUrl(),
            // SVGs and not-yet-processed images have no thumb; fall back to the original.
            'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
            'webp_url' => $media->hasGeneratedConversion('webp') ? $media->getUrl('webp') : null,
            'medium_url' => $media->hasGeneratedConversion('medium') ? $media->getUrl('medium') : null,
            'created_at' => $media->created_at,
        ];
    }
}
