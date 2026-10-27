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
    public function create(): Response
    {
        return Inertia::render('Import/Create');
    }

    public function store(Request $request, WordPressImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:xml', 'mimetypes:text/xml,application/xml'],
            // Download the images in the content afterwards, on the queue. Off unless asked for.
            'download_images' => ['sometimes', 'boolean'],
        ]);

        $results = $importer->import($request->file('file')->getRealPath(), $request->user(), $request->boolean('download_images'));

        // Kept in the session (not flashed) so refreshing the result page still works.
        $request->session()->put('import_result', $results);

        return redirect()->route('import.result');
    }

    public function result(Request $request): Response|RedirectResponse
    {
        $results = $request->session()->get('import_result');

        if ($results === null) {
            return redirect()->route('import.create');
        }

        return Inertia::render('Import/Result', ['results' => $results]);
    }

    /** Progress of an import's image downloads (polled by the import page). */
    public function status(ImportRun $run): JsonResponse
    {
        $failures = $run->failures ?? [];

        return response()->json([
            'id' => $run->id,
            'status' => $run->status,
            'items_total' => $run->items_total,
            'items_done' => $run->items_done,
            'images_found' => $run->images_found,
            'images_downloaded' => $run->images_downloaded,
            'images_reused' => $run->images_reused,
            'images_skipped' => $run->images_skipped,
            'bytes_downloaded' => $run->bytes_downloaded,
            'failures' => array_slice($failures, 0, 50),
            'failures_recorded' => count($failures),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ]);
    }
}
