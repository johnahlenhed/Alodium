<?php

namespace App\Http\Controllers;

use App\Models\ImportRun;
use App\Services\WordPressImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    /** The export file's size limit, in KB (also shown and checked on the upload form). */
    private const MAX_UPLOAD_KB = 20480;

    public function create(): Response
    {
        // Only the fields the list shows: never the failures or the report.
        $recentRuns = ImportRun::query()
            ->with('user:id,name')
            ->latest('id')
            ->limit(5)
            ->get(['id', 'user_id', 'status', 'download_images', 'imported_posts', 'imported_pages', 'images_downloaded', 'images_skipped', 'started_at'])
            ->map(fn (ImportRun $run) => [
                'id' => $run->id,
                'started_at' => $run->started_at?->toIso8601String(),
                'user' => $run->user?->name,
                'status' => $run->status,
                'download_images' => $run->download_images,
                'imported_posts' => $run->imported_posts,
                'imported_pages' => $run->imported_pages,
                'images_downloaded' => $run->images_downloaded,
                'images_skipped' => $run->images_skipped,
            ]);

        return Inertia::render('Import/Create', [
            'limits' => [
                'max_upload_bytes' => self::MAX_UPLOAD_KB * 1024,
                'max_image_bytes' => (int) config('import.max_image_bytes'),
                'max_megapixels' => (int) config('import.max_megapixels'),
                'max_images_per_run' => (int) config('import.max_images_per_run'),
            ],
            'recentRuns' => $recentRuns,
        ]);
    }

    public function store(Request $request, WordPressImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB, 'extensions:xml', 'mimetypes:text/xml,application/xml'],
            // Download the images (content and featured) afterwards, on the queue. Off unless asked for.
            'download_images' => ['sometimes', 'boolean'],
        ]);

        $results = $importer->import($request->file('file')->getRealPath(), $request->user(), $request->boolean('download_images'));

        return redirect()->route('import.runs.show', $results['run_id']);
    }

    /** The old result page: now the latest run's page. */
    public function result(): RedirectResponse
    {
        $latest = ImportRun::query()->latest('id')->value('id');

        return $latest ? redirect()->route('import.runs.show', $latest) : redirect()->route('import.create');
    }

    /** One import: the text import's result and the image downloads (polled through status()). */
    public function show(ImportRun $run): Response
    {
        $run->load('user:id,name');
        $report = $run->report ?? [];

        return Inertia::render('Import/Run', [
            'run' => [
                'id' => $run->id,
                'user' => $run->user?->name,
                'started_at' => $run->started_at?->toIso8601String(),
                'imported_posts' => $run->imported_posts,
                'imported_pages' => $run->imported_pages,
                'skipped' => $report['skipped'] ?? [],
                'errors' => $report['errors'] ?? [],
                'found' => (object) ($report['found'] ?? []),
            ],
            'status' => $this->statusOf($run),
            'statusUrl' => route('import.runs.status', $run),
        ]);
    }

    /** Progress of an import's image downloads (polled by the run page). */
    public function status(ImportRun $run): JsonResponse
    {
        return response()->json($this->statusOf($run));
    }

    private function statusOf(ImportRun $run): array
    {
        $failures = $run->failures ?? [];

        return [
            'id' => $run->id,
            'status' => $run->status,
            'download_images' => $run->download_images,
            'items_total' => $run->items_total,
            'items_done' => $run->items_done,
            'images_found' => $run->images_found,
            'images_downloaded' => $run->images_downloaded,
            'images_reused' => $run->images_reused,
            'images_skipped' => $run->images_skipped,
            'bytes_downloaded' => $run->bytes_downloaded,
            // Every skipped image, counted per reason (failures keeps only the first 200).
            'skipped_by_reason' => (object) ($run->skipped_reasons ?? []),
            'failures' => array_slice($failures, 0, 50),
            'failures_recorded' => count($failures),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }
}
