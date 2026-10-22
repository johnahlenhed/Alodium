<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Contact form submissions (from /contact and contact form blocks).
 * Admins and editors can read them; only admins can delete (see routes/web.php).
 * The message is plain text: the React pages output it as escaped text, never HTML.
 */
class SubmissionController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Submissions/Index', [
            'submissions' => Submission::latest()->latest('id')->paginate(25)->through(fn (Submission $submission) => [
                'id' => $submission->id,
                'name' => $submission->name,
                'email' => $submission->email,
                'excerpt' => Str::limit(preg_replace('/\s+/u', ' ', $submission->message), 100),
                'read' => $submission->read,
                'created_at' => $submission->created_at,
            ]),
        ]);
    }

    /** Opening a submission marks it as read. */
    public function show(Submission $submission): Response
    {
        if (! $submission->read) {
            $submission->update(['read' => true]);
        }

        return Inertia::render('Submissions/Show', [
            'submission' => $submission->only(['id', 'name', 'email', 'message', 'read', 'created_at']),
        ]);
    }

    /** Back to the list: staying on the detail page would mark it as read again. */
    public function markUnread(Submission $submission): RedirectResponse
    {
        $submission->update(['read' => false]);

        return redirect()->route('submissions.index')->with('success', "The message from {$submission->name} is marked as unread.");
    }

    public function destroy(Submission $submission): RedirectResponse
    {
        $submission->delete();

        return redirect()->route('submissions.index')->with('success', "The message from {$submission->name} was deleted.");
    }
}
