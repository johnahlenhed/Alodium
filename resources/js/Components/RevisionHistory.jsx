import { Link } from '@inertiajs/react';

const formatDateTime = (value) =>
    new Date(value).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' });

/**
 * Snapshots of earlier published versions, newest first, each restorable.
 * Not a diff viewer: restoring replaces the content (not the title or meta
 * fields), after saving the current content as a revision.
 *
 * restoreRoute: e.g. "posts.revisions.restore"; modelId: the post/page id.
 */
export default function RevisionHistory({ revisions, restoreRoute, modelId }) {
    return (
        <section className="bg-surface p-4 shadow sm:rounded-lg sm:p-8">
            <h3 className="text-lg font-medium text-gray-900">
                Revision history <span className="text-gray-500">({revisions.length})</span>
            </h3>
            <p className="mt-1 text-sm text-gray-500">
                Earlier published versions. Saving a published post or page keeps the
                previous version here (the 20 most recent).
            </p>

            {revisions.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500">No revision history yet.</p>
            ) : (
                <ul className="mt-4 divide-y divide-gray-100">
                    {revisions.map((revision) => (
                        <li key={revision.id} className="flex items-start justify-between gap-4 py-3">
                            <div className="min-w-0">
                                <div className="text-sm font-medium text-gray-900">
                                    <time dateTime={revision.created_at}>
                                        {formatDateTime(revision.created_at)}
                                    </time>
                                    {revision.title && (
                                        <span className="ms-2 font-normal text-gray-500">
                                            {revision.title}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-1 truncate text-sm text-gray-600">
                                    {revision.excerpt || <em>(empty content)</em>}
                                </p>
                            </div>
                            <Link
                                href={route(restoreRoute, [modelId, revision.id])}
                                method="post"
                                as="button"
                                preserveScroll
                                onBefore={() =>
                                    confirm('Restore this version? Current content will be saved as a revision first.')
                                }
                                className="shrink-0 rounded-md border border-gray-300 px-3 py-1.5 text-xs font-semibold uppercase tracking-widest text-gray-700 hover:bg-gray-50"
                            >
                                Restore
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
