import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import {
    connectionLost, formatMegabytes, groupReasons, initialPollState, isActive, moreFailures,
    pollDelay, pollFailed, pollSucceeded, progress, reasonLabel, showImagePanel, truncateMiddle,
} from '@/utils/importRun';
import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

function Stat({ label, value }) {
    return (
        <div className="bg-surface p-6 shadow sm:rounded-lg">
            <div className="text-sm font-medium text-gray-500">{label}</div>
            <div className="mt-2 text-3xl font-semibold text-gray-900">{value}</div>
        </div>
    );
}

const linkClass = 'rounded text-brand-600 hover:text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500';

/**
 * Polls the status endpoint while the run is going: every 3 s, every 10 s after
 * three failures in a row, paused while the tab is hidden, aborted on unmount.
 * The state changes themselves are pure (utils/importRun.js).
 */
function useRunStatus(initialStatus, url) {
    const [state, setState] = useState(() => initialPollState(initialStatus, Date.now()));
    const stateRef = useRef(state);

    useEffect(() => {
        if (!isActive(initialStatus)) return undefined;

        const controller = new AbortController();
        let timer = null;
        let inFlight = false;
        let stopped = false;

        const update = (next) => {
            stateRef.current = next;
            setState(next);
        };

        const schedule = () => {
            clearTimeout(timer);
            if (!stopped && !document.hidden) {
                timer = setTimeout(poll, pollDelay(stateRef.current.failedPolls));
            }
        };

        const poll = async () => {
            if (stopped || inFlight) return;
            inFlight = true;
            try {
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                const next = pollSucceeded(stateRef.current, await response.json(), Date.now());
                update(next);
                if (!isActive(next.status)) stopped = true;
            } catch {
                if (controller.signal.aborted) return;
                update(pollFailed(stateRef.current));
            } finally {
                inFlight = false;
            }
            schedule();
        };

        const onVisibilityChange = () => {
            clearTimeout(timer);
            // Back in view: catch up at once instead of waiting for the next tick.
            if (!document.hidden) poll();
        };

        document.addEventListener('visibilitychange', onVisibilityChange);
        schedule();

        return () => {
            stopped = true;
            controller.abort();
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, [initialStatus, url]);

    return state;
}

function ProgressBar({ status }) {
    const { handled, total, percent, label } = progress(status);

    return (
        <div>
            <div className="flex items-baseline justify-between gap-4 text-sm">
                <span id="image-progress-label" className="font-medium text-gray-900">{label}</span>
                <span className="text-gray-600">{percent}%</span>
            </div>
            <div
                role="progressbar"
                aria-labelledby="image-progress-label"
                aria-valuemin={0}
                aria-valuemax={total}
                aria-valuenow={handled}
                aria-valuetext={label}
                className="mt-2 h-3 overflow-hidden rounded bg-gray-200"
            >
                <div className="h-full bg-primary transition-all" style={{ width: `${percent}%` }} />
            </div>
        </div>
    );
}

function Counters({ status }) {
    const items = [
        ['Downloaded', status.images_downloaded],
        ['Reused', status.images_reused],
        ['Skipped', status.images_skipped],
        ['Downloaded size', formatMegabytes(status.bytes_downloaded)],
    ];

    return (
        <dl className="grid grid-cols-2 gap-4 sm:grid-cols-4">
            {items.map(([label, value]) => (
                <div key={label} className="rounded-md bg-gray-50 p-3">
                    <dt className="text-xs font-medium text-gray-500">{label}</dt>
                    <dd className="mt-1 text-lg font-semibold text-gray-900">{value}</dd>
                </div>
            ))}
        </dl>
    );
}

function Failures({ status }) {
    const groups = groupReasons(status.skipped_by_reason);
    const more = moreFailures(status);
    if (groups.length === 0 && status.failures.length === 0) return null;

    return (
        <div className="space-y-4">
            {groups.length > 0 && (
                <div>
                    <h4 className="text-sm font-semibold text-gray-900">Skipped images by reason</h4>
                    <ul className="mt-2 space-y-1 text-sm text-gray-700">
                        {groups.map((group) => (
                            <li key={group.code} className="flex justify-between gap-4 sm:max-w-md">
                                <span>{group.label}</span>
                                <span className="font-medium">{group.count}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {status.failures.length > 0 && (
                <div>
                    <h4 className="text-sm font-semibold text-gray-900">Details</h4>
                    <ul className="mt-2 divide-y divide-gray-100 text-sm">
                        {status.failures.map((failure, i) => (
                            <li key={i} className="py-2">
                                <div className="text-gray-900">{failure.item}</div>
                                {/* The address as plain text, never a link: it comes from the export file. */}
                                {failure.url && (
                                    <div className="break-all font-mono text-xs text-gray-600" title={failure.url}>
                                        {truncateMiddle(failure.url, 90)}
                                    </div>
                                )}
                                <div className="text-gray-600">
                                    {failure.url ? reasonLabel(failure.reason) : failure.reason}
                                </div>
                            </li>
                        ))}
                    </ul>
                    {more > 0 && <p className="mt-2 text-sm text-gray-600">and {more} more</p>}
                </div>
            )}
        </div>
    );
}

function ImagePanel({ initialStatus, statusUrl, errors }) {
    const { status, failedPolls, stalled, announcement } = useRunStatus(initialStatus, statusUrl);
    const active = isActive(status);
    const nothingFound = status.status === 'done' && status.images_found === 0;

    return (
        <section aria-labelledby="images-heading" className="space-y-6 bg-surface p-6 shadow sm:rounded-lg">
            <h3 id="images-heading" className="text-base font-semibold text-gray-900">Images</h3>

            {/* Milestones only (started, finished, failed), not every poll. */}
            <p className="sr-only" aria-live="polite">{announcement}</p>

            {status.status === 'failed' ? (
                <div className="rounded-md border border-danger-200 bg-danger-50 p-4 text-sm text-danger-800">
                    <p className="font-medium">The import failed, so no images were downloaded.</p>
                    {errors[0] && <p className="mt-1">Reason: {errors[0]}</p>}
                </div>
            ) : nothingFound ? (
                <p className="text-sm text-gray-600">
                    No images from the exported site were found in the imported posts and pages, or as
                    featured images. Nothing was downloaded.
                </p>
            ) : (
                <>
                    {active && (
                        <p className="text-sm text-gray-600">
                            Images are being copied from the old site in the background. You can leave
                            this page; the download carries on.
                        </p>
                    )}
                    <ProgressBar status={status} />
                    <Counters status={status} />

                    {active && connectionLost(failedPolls) && (
                        <p className="rounded-md border border-warning-200 bg-warning-50 p-3 text-sm text-warning-900" role="status">
                            Connection lost, retrying…
                        </p>
                    )}
                    {active && stalled && !connectionLost(failedPolls) && (
                        <p className="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                            Nothing has changed for a minute. The queue worker may not be running: on the
                            server it is the Supervisor program <code>alodium-worker</code> (check
                            with <code>supervisorctl status</code>); locally, run{' '}
                            <code>php artisan queue:work</code>.
                        </p>
                    )}

                    {status.status === 'done' && (
                        <div className="space-y-2 rounded-md border border-success-200 bg-success-50 p-4 text-sm text-success-900">
                            <p className="font-medium">
                                Done: {status.images_downloaded} downloaded, {status.images_reused} reused
                                and {status.images_skipped} skipped, {formatMegabytes(status.bytes_downloaded)} in total.
                            </p>
                            <p>The public site updates in a minute or two.</p>
                            <p>
                                Links to pages and files on the old site (for example attachment pages)
                                are not changed.
                            </p>
                        </div>
                    )}

                    {status.status === 'done' && <Failures status={status} />}
                </>
            )}
        </section>
    );
}

export default function Run({ run, status, statusUrl }) {
    const { imported_posts, imported_pages, skipped, warnings = [], errors, found = {} } = run;
    const foundSummary = Object.entries(found)
        .map(([type, count]) => `${count} × ${type}`)
        .join(', ');
    const heading = useRef(null);

    // Arriving here after the upload: start reading at the heading.
    useEffect(() => {
        heading.current?.focus();
    }, []);

    return (
        <AuthenticatedLayout
            header={
                <h2
                    ref={heading}
                    tabIndex={-1}
                    className="rounded text-xl font-semibold leading-tight text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                >
                    Import result
                </h2>
            }
        >
            <Head title="Import result" />

            <div className="py-12">
                <div className="mx-auto max-w-5xl space-y-6 sm:px-6 lg:px-8">
                    <div className="grid gap-6 sm:grid-cols-3">
                        <Stat label="Posts imported" value={imported_posts} />
                        <Stat label="Pages imported" value={imported_pages} />
                        <Stat label="Skipped" value={skipped.length} />
                    </div>

                    <p className="text-sm text-gray-600">
                        <span className="font-medium text-gray-700">Found in file:</span>{' '}
                        {foundSummary || 'no items'}. Only posts and pages are imported;
                        attachments are used as featured images, and other types (menus,
                        revisions, custom post types) are ignored.
                    </p>

                    {errors.length > 0 && (
                        <div className="rounded-md border border-danger-200 bg-danger-50 p-6">
                            <h3 className="text-sm font-semibold text-danger-800">
                                Errors ({errors.length})
                            </h3>
                            <ul className="mt-2 list-disc space-y-1 ps-5 text-sm text-danger-700">
                                {errors.map((error, i) => (
                                    <li key={i}>{error}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {warnings.length > 0 && (
                        <div className="overflow-hidden rounded-md border border-warning-200 bg-warning-50">
                            <h3 className="px-6 pt-6 text-sm font-semibold text-warning-900">
                                Imported with warnings ({warnings.length})
                            </h3>
                            <p className="mt-1 px-6 text-sm text-warning-800">
                                These posts and pages were imported, but something in them was converted, lost
                                or repaired. Check them before publishing.
                            </p>
                            <div className="overflow-x-auto">
                                <table className="mt-4 min-w-full divide-y divide-warning-200 text-sm">
                                    <thead className="bg-warning-100/60">
                                        <tr className="text-left text-xs font-medium uppercase tracking-wider text-warning-900">
                                            <th scope="col" className="px-6 py-3">Title</th>
                                            <th scope="col" className="px-6 py-3">Type</th>
                                            <th scope="col" className="px-6 py-3">Warning</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-warning-200">
                                        {warnings.map((warning, i) => (
                                            <tr key={i}>
                                                <td className="px-6 py-3 text-gray-900">
                                                    {warning.title}
                                                    <span className="block text-xs text-gray-600">{warning.slug}</span>
                                                </td>
                                                <td className="px-6 py-3 text-gray-700">{warning.type}</td>
                                                <td className="px-6 py-3 text-gray-700">{warning.message}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {skipped.length > 0 && (
                        <div className="overflow-hidden bg-surface shadow sm:rounded-lg">
                            <h3 className="px-6 pt-6 text-sm font-semibold text-gray-900">Skipped items</h3>
                            <div className="overflow-x-auto">
                                <table className="mt-4 min-w-full divide-y divide-gray-200 text-sm">
                                    <thead className="bg-gray-50">
                                        <tr className="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            <th scope="col" className="px-6 py-3">Title</th>
                                            <th scope="col" className="px-6 py-3">Type</th>
                                            <th scope="col" className="px-6 py-3">Reason</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-gray-200">
                                        {skipped.map((item, i) => (
                                            <tr key={i}>
                                                <td className="px-6 py-3 text-gray-900">{item.title}</td>
                                                <td className="px-6 py-3 text-gray-600">{item.type}</td>
                                                <td className="px-6 py-3 text-gray-600">{item.reason}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    )}

                    {showImagePanel(status) && <ImagePanel initialStatus={status} statusUrl={statusUrl} errors={errors} />}

                    <nav aria-label="Next steps" className="flex flex-wrap items-center gap-4 text-sm">
                        <span className="text-gray-600">Review the imported content:</span>
                        <Link href={route('posts.index')} className={linkClass}>
                            Posts →
                        </Link>
                        <Link href={route('pages.index')} className={linkClass}>
                            Pages →
                        </Link>
                        <Link href={route('media.index')} className={linkClass}>
                            Media library →
                        </Link>
                        <Link href={route('import.create')} className="ms-auto rounded text-gray-600 hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                            Import another file
                        </Link>
                    </nav>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
