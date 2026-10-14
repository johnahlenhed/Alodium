<?php

namespace App\Http\Controllers;

use App\Services\WordPressImporter;
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
        ]);

        $results = $importer->import($request->file('file')->getRealPath(), $request->user());

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
}
