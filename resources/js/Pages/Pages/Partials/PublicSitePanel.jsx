import { Link, router } from '@inertiajs/react';
import { CircleCheck, Clock, ExternalLink, Info, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

// While a rebuild is pending: every 5 s for the first 2 minutes, then every 15 s,
// and give up after 15 minutes (the dashboard's Site status takes over then).
const FAST_POLL_MS = 5000;
const SLOW_POLL_MS = 15000;
const FAST_FOR_MS = 2 * 60 * 1000;
const GIVE_UP_AFTER_MS = 15 * 60 * 1000;
const WAITING = ['pending', 'unknown'];

const linkClass = 'font-medium text-brand-700 underline underline-offset-2 hover:text-brand-900';

/**
 * Asks the server whether the page is live while a rebuild is pending, and stops
 * once it is (or failed). Pauses while the tab is hidden, like the import run page.
 */
function useSiteStatus(pageId, initial) {
    const [status, setStatus] = useState(initial);
    const [gaveUp, setGaveUp] = useState(false);
    const statusRef = useRef(initial);
    useEffect(() => {
        statusRef.current = initial;
        setStatus(initial);
        setGaveUp(false);
    }, [initial]);

    useEffect(() => {
        if (!WAITING.includes(initial.state)) return undefined;
        const started = Date.now();
        const controller = new AbortController();
        let timer = null;

        const schedule = () => {
            clearTimeout(timer);
            if (!WAITING.includes(statusRef.current.state) || document.hidden) return;
            const elapsed = Date.now() - started;
            if (elapsed > GIVE_UP_AFTER_MS) {
                setGaveUp(true);
                return;
            }
            timer = setTimeout(poll, elapsed < FAST_FOR_MS ? FAST_POLL_MS : SLOW_POLL_MS);
        };
        const poll = async () => {
            try {
                const response = await fetch(route('pages.site-status', pageId), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (response.ok) {
                    const next = await response.json();
                    statusRef.current = next;
                    setStatus(next);
                }
            } catch {
                if (controller.signal.aborted) return;
                // A failed poll is just tried again at the next tick.
            }
            schedule();
        };
        const onVisibilityChange = () => (document.hidden ? clearTimeout(timer) : poll());

        document.addEventListener('visibilitychange', onVisibilityChange);
        schedule();
        return () => {
            controller.abort();
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibilityChange);
        };
    }, [pageId, initial]);

    return { status, gaveUp };
}

function ViewOnSite({ url, children = 'View on site' }) {
    return (
        <a href={url} target="_blank" rel="noopener noreferrer" className={`inline-flex items-center gap-1 ${linkClass}`}>
            {children}
            <ExternalLink className="h-3.5 w-3.5" aria-hidden="true" />
            <span className="sr-only">(opens in a new tab)</span>
        </a>
    );
}

function Line({ icon: Icon, iconClass, children }) {
    return (
        <div className="flex items-start gap-2">
            <Icon className={`mt-0.5 h-4 w-4 shrink-0 ${iconClass}`} aria-hidden="true" />
            <div className="min-w-0 space-y-1">{children}</div>
        </div>
    );
}

/** Where the page is on the public site, whether it is live yet, and how it gets into the menu. */
function LiveStatus({ status, gaveUp }) {
    // currentUrl: where the version on the site now is (the old address, after a rename).
    const { state, path, url, onSite, currentUrl, publishAt } = status;
    const address = <span className="font-mono">{path}</span>;

    switch (state) {
        case 'live':
            return (
                <Line icon={CircleCheck} iconClass="text-success-700">
                    <p>Live on the public site at {address}.</p>
                    {url && <ViewOnSite url={url} />}
                </Line>
            );
        case 'pending':
        case 'unknown':
            return (
                <Line icon={Clock} iconClass="text-gray-500">
                    <p>
                        {state === 'pending' ? 'Being published' : 'Published'} at {address}. The public site is updated in
                        the background, usually within a minute or two
                        {gaveUp ? '. This is taking longer than usual: the dashboard’s Site status shows whether something went wrong.' : '; this message updates by itself.'}
                    </p>
                    {/* Only a version that is actually built is linked to. */}
                    {onSite && currentUrl && (
                        <p>
                            Until then, the site shows the previous version: <ViewOnSite url={currentUrl}>View the current version</ViewOnSite>
                        </p>
                    )}
                </Line>
            );
        case 'failed':
            return (
                <Line icon={TriangleAlert} iconClass="text-warning-700">
                    <p>
                        Updating the public site failed, so {onSite ? 'it still shows the previous version of this page' : 'this page isn’t on it yet'}.
                        It is tried again the next time anything is published. The dashboard’s Site status has details for your developer.
                    </p>
                    {onSite && currentUrl && <ViewOnSite url={currentUrl}>View the current version</ViewOnSite>}
                </Line>
            );
        case 'not_configured':
            return (
                <Line icon={Info} iconClass="text-gray-500">
                    <p>
                        Published at {address}. This server doesn’t update the public site automatically, so the page appears
                        there after the next rebuild.
                    </p>
                </Line>
            );
        case 'scheduled':
            return (
                <Line icon={Clock} iconClass="text-gray-500">
                    <p>
                        Scheduled: it goes on the public site at {address} on{' '}
                        {new Date(publishAt).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' })}.
                    </p>
                </Line>
            );
        default:
            return (
                <Line icon={Info} iconClass="text-gray-500">
                    <p>Not on the public site: it’s a draft. Once published, its address is {address}.</p>
                </Line>
            );
    }
}

/**
 * The menu and the home page, from the page itself: an admin adds the page to
 * the menu with one click (PageController::addToNavigation); choosing the front
 * page stays on the Settings page, which explains what it replaces.
 */
function MenuAndHome({ page, isPublic, menu }) {
    const [adding, setAdding] = useState(false);
    const navigationUrl = `${route('settings.edit')}#section-navigation`;
    const frontPageUrl = `${route('settings.edit')}#section-front-page`;

    if (!isPublic) {
        return <p>Once it’s published, it can be added to the site’s menu.</p>;
    }
    if (!menu.canEdit) {
        return (
            <p>
                {menu.inMenu ? 'It’s in the site’s menu.' : 'It isn’t in the site’s menu.'} The menu and the home page are
                changed by an admin, under Settings.
            </p>
        );
    }

    const add = () =>
        router.post(route('pages.navigation.add', page.id), {}, {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setAdding(true),
            onFinish: () => setAdding(false),
        });

    return (
        <div className="space-y-2">
            {menu.inMenu ? (
                <p>
                    It’s in the site’s menu. <Link href={navigationUrl} className={linkClass}>Change the menu</Link>
                </p>
            ) : menu.full ? (
                <p>
                    It isn’t in the site’s menu, and the menu is full.{' '}
                    <Link href={navigationUrl} className={linkClass}>Make room under Settings, Navigation</Link>
                </p>
            ) : (
                <p className="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <span>It isn’t in the site’s menu yet.</span>
                    <button
                        type="button"
                        onClick={add}
                        disabled={adding}
                        className="rounded-md border border-gray-400 bg-surface px-3 py-1.5 text-sm font-medium text-gray-800 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-50"
                    >
                        Add to the menu
                    </button>
                </p>
            )}
            {menu.isFrontPage ? (
                <p>It’s the site’s home page.</p>
            ) : (
                <p>
                    To show it on the home page instead of the latest posts:{' '}
                    <Link href={frontPageUrl} className={linkClass}>Settings, Front page</Link>
                </p>
            )}
        </div>
    );
}

export default function PublicSitePanel({ page, initial, menu }) {
    const { status, gaveUp } = useSiteStatus(page.id, initial);
    const isPublic = !['draft', 'scheduled'].includes(status.state);

    return (
        <section aria-labelledby="public-site-heading" className="bg-surface p-4 text-sm text-gray-700 shadow sm:rounded-lg sm:p-6">
            <h3 id="public-site-heading" className="mb-3 text-base font-semibold text-gray-900">
                On the public site
            </h3>
            {/* Announced when it changes, e.g. from "being published" to "live". */}
            <div role="status" aria-live="polite">
                <LiveStatus status={status} gaveUp={gaveUp} />
            </div>
            <div className="mt-3 border-t border-gray-100 pt-3">
                <MenuAndHome page={page} isPublic={isPublic} menu={menu} />
            </div>
        </section>
    );
}
