import VisitsChart from '@/Components/VisitsChart';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    CalendarClock,
    CircleCheck,
    Database,
    ExternalLink,
    Eye,
    FilePlus,
    FileText,
    Globe,
    Inbox,
    Info,
    Minus,
    RefreshCw,
    Server,
    TriangleAlert,
} from 'lucide-react';

const PERIODS = [7, 30];

const formatDateTime = (value) => new Date(value).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' });

/** 80 -> "1m 20s", 45 -> "45s", 3700 -> "1h 1m". */
export function formatDuration(seconds) {
    const s = Math.max(0, Math.round(seconds || 0));
    if (s < 60) return `${s}s`;
    if (s < 3600) return `${Math.floor(s / 60)}m ${s % 60}s`;
    return `${Math.floor(s / 3600)}h ${Math.floor((s % 3600) / 60)}m`;
}

function formatAge(hours) {
    if (hours < 1) return 'less than an hour ago';
    if (hours < 48) return `${Math.round(hours)} hours ago`;
    return `${Math.round(hours / 24)} days ago`;
}

function Section({ title, icon: Icon, action, children, className = '' }) {
    return (
        <section className={`rounded-lg bg-surface p-5 shadow-sm ${className}`}>
            <div className="mb-4 flex items-center justify-between gap-3">
                <h3 className="flex items-center gap-2 text-base font-semibold text-gray-900">
                    {Icon && <Icon className="h-4 w-4 text-gray-500" aria-hidden="true" />}
                    {title}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}

const actionClass =
    'inline-flex items-center gap-2 rounded-md border border-gray-300 bg-surface px-3 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2';

function Header({ userName, publicSiteUrl, unread }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-4">
            <h2 className="text-xl font-semibold text-gray-900">Welcome back, {userName}</h2>
            <nav aria-label="Quick actions" className="flex flex-wrap gap-2">
                <Link href={route('posts.create')} className={actionClass}>
                    <FilePlus className="h-4 w-4" aria-hidden="true" /> New post
                </Link>
                <Link href={route('pages.create')} className={actionClass}>
                    <FileText className="h-4 w-4" aria-hidden="true" /> New page
                </Link>
                <Link href={route('submissions.index')} className={actionClass}>
                    <Inbox className="h-4 w-4" aria-hidden="true" /> Submissions
                    {unread > 0 && (
                        <span className="rounded-full bg-primary px-1.5 py-0.5 text-xs font-semibold leading-none text-on-primary">
                            {unread}
                            <span className="sr-only"> unread</span>
                        </span>
                    )}
                </Link>
                {publicSiteUrl && (
                    <a href={publicSiteUrl} target="_blank" rel="noopener noreferrer" className={actionClass}>
                        <ExternalLink className="h-4 w-4" aria-hidden="true" /> View site
                        <span className="sr-only">(opens in a new tab)</span>
                    </a>
                )}
            </nav>
        </div>
    );
}

function Change({ summary }) {
    if (summary.isNew) {
        return <p className="mt-1 text-sm text-gray-600">new (no page views in the previous {summary.days} days)</p>;
    }
    if (summary.change === null) {
        return <p className="mt-1 text-sm text-gray-500">No earlier page views to compare with</p>;
    }
    const Icon = summary.change > 0 ? ArrowUp : summary.change < 0 ? ArrowDown : Minus;
    const sign = summary.change > 0 ? '+' : '';
    return (
        <p className="mt-1 flex items-center gap-1 text-sm text-gray-600">
            <Icon className="h-4 w-4" aria-hidden="true" />
            {sign}
            {summary.change}% vs the previous {summary.days} days
        </p>
    );
}

function Stat({ label, value, children }) {
    return (
        <div className="rounded-md border border-gray-100 p-4">
            <div className="text-sm text-gray-600">{label}</div>
            <div className="mt-1 text-2xl font-semibold text-gray-900">{value}</div>
            {children}
        </div>
    );
}

function Visitors({ summary }) {
    const changePeriod = (days) =>
        router.get(route('dashboard'), { days }, { only: ['visitors', 'topContent', 'sources'], preserveScroll: true, preserveState: true });

    return (
        <Section
            title="Page views"
            icon={Eye}
            className="lg:col-span-2"
            action={
                <div className="inline-flex rounded-md shadow-sm" role="group" aria-label="Period">
                    {PERIODS.map((days) => (
                        <button
                            key={days}
                            type="button"
                            onClick={() => changePeriod(days)}
                            aria-pressed={summary.days === days}
                            className={`border border-gray-300 px-3 py-1.5 text-sm font-medium first:rounded-s-md last:rounded-e-md focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ${summary.days === days ? 'bg-primary text-on-primary' : 'bg-surface text-gray-700 hover:bg-gray-50'}`}
                        >
                            {days} days
                        </button>
                    ))}
                </div>
            }
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <Stat label={`Page views, last ${summary.days} days`} value={summary.pageViews}>
                    <Change summary={summary} />
                </Stat>
                <Stat label="Average time on page" value={formatDuration(summary.avgDuration)} />
                <Stat label="Average scroll depth" value={`${summary.avgScrollDepth}%`} />
            </div>
            <p className="mt-3 text-sm text-gray-600">Alodium counts page views, not people. It stores no visitor identifier.</p>

            <div className="mt-4">
                {summary.pageViews === 0 ? (
                    <p className="rounded-md bg-gray-50 px-4 py-8 text-center text-sm text-gray-500">
                        No page views in this period yet.
                    </p>
                ) : (
                    <VisitsChart data={summary.daily} label={`Page views per day, last ${summary.days} days`} height="h-56" />
                )}
                <p className="mt-2 text-xs text-gray-500">Days run from midnight to midnight, {summary.timezone}.</p>
            </div>
        </Section>
    );
}

function CountList({ rows, empty, renderLabel }) {
    if (rows.length === 0) return <p className="text-sm text-gray-500">{empty}</p>;
    return (
        <ol className="divide-y divide-gray-100 text-sm">
            {rows.map((row, i) => (
                <li key={i} className="flex items-center justify-between gap-3 py-2">
                    <span className="min-w-0 truncate">{renderLabel(row)}</span>
                    <span className="shrink-0 tabular-nums text-gray-900">{row.count}</span>
                </li>
            ))}
        </ol>
    );
}

function TopContent({ rows, days }) {
    return (
        <Section title={`Top content, last ${days} days`} icon={FileText}>
            <CountList
                rows={rows}
                empty="No page views in this period yet."
                renderLabel={(row) => (
                    <>
                        {row.editUrl ? (
                            <Link href={row.editUrl} className="font-medium text-brand-700 hover:underline">
                                {row.title}
                            </Link>
                        ) : (
                            <span className="font-medium text-gray-900">{row.title}</span>
                        )}
                        {row.title !== row.path && <span className="ms-2 text-gray-500">{row.path}</span>}
                    </>
                )}
            />
        </Section>
    );
}

function Sources({ rows, days }) {
    return (
        <Section title={`Traffic sources, last ${days} days`} icon={ExternalLink}>
            <CountList rows={rows} empty="No page views in this period yet." renderLabel={(row) => <span className="text-gray-900">{row.label}</span>} />
        </Section>
    );
}

function ContentStatus({ content }) {
    const items = [
        ['Published posts', content.publishedPosts],
        ['Published pages', content.publishedPages],
        ['Drafts', content.drafts],
        ['Scheduled', content.scheduled],
    ];

    return (
        <Section title="Content" icon={CalendarClock}>
            <dl className="grid grid-cols-2 gap-3">
                {items.map(([label, value]) => (
                    <div key={label} className="rounded-md border border-gray-100 p-3">
                        <dt className="text-sm text-gray-600">{label}</dt>
                        <dd className="text-xl font-semibold text-gray-900">{value}</dd>
                    </div>
                ))}
            </dl>
            <p className="mt-3 text-sm text-gray-600">
                {content.nextScheduledAt ? <>Next scheduled publish: {formatDateTime(content.nextScheduledAt)}</> : 'Nothing is scheduled.'}
            </p>

            <h4 className="mt-5 text-sm font-semibold text-gray-900">Needs attention</h4>
            {content.missingMetaCount === 0 ? (
                <p className="mt-1 flex items-center gap-2 text-sm text-gray-600">
                    <CircleCheck className="h-4 w-4 text-success-700" aria-hidden="true" />
                    Every published post and page has a meta description.
                </p>
            ) : (
                <>
                    <p className="mt-1 text-sm text-gray-700">
                        {content.missingMetaCount} published {content.missingMetaCount === 1 ? 'item has' : 'items have'} no meta
                        description, so search engines use the start of the text instead:
                    </p>
                    <ul className="mt-2 space-y-1 text-sm">
                        {content.missingMeta.map((item) => (
                            <li key={item.editUrl}>
                                <Link href={item.editUrl} className="text-brand-700 hover:underline">
                                    {item.title}
                                </Link>
                                <span className="ms-2 text-gray-500">{item.type}</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </Section>
    );
}

function Submissions({ submissions }) {
    return (
        <Section
            title="Submissions"
            icon={Inbox}
            action={
                <Link href={route('submissions.index')} className="text-sm text-brand-700 hover:underline">
                    View all
                </Link>
            }
        >
            <p className="text-sm text-gray-700">
                {submissions.unread === 0 ? 'No unread messages.' : `${submissions.unread} unread ${submissions.unread === 1 ? 'message' : 'messages'}.`}
            </p>
            {submissions.latest.length === 0 ? (
                <p className="mt-3 text-sm text-gray-500">No messages yet.</p>
            ) : (
                <ul className="mt-3 divide-y divide-gray-100 text-sm">
                    {submissions.latest.map((submission) => (
                        <li key={submission.id} className="py-2">
                            {/* Plain text from visitors: rendered as escaped text, never HTML. */}
                            <Link href={route('submissions.show', submission.id)} className={`hover:underline ${submission.read ? 'text-gray-900' : 'font-semibold text-gray-900'}`}>
                                {submission.name}
                            </Link>
                            {!submission.read && <span className="ms-2 text-xs font-medium text-brand-700">unread</span>}
                            <span className="ms-2 text-gray-500">{formatDateTime(submission.created_at)}</span>
                            <p className="truncate text-gray-600">{submission.excerpt}</p>
                        </li>
                    ))}
                </ul>
            )}
        </Section>
    );
}

function StatusRow({ icon: Icon, label, children }) {
    return (
        <div className="flex items-start gap-3 py-2">
            <Icon className="mt-0.5 h-4 w-4 shrink-0 text-gray-500" aria-hidden="true" />
            <div className="min-w-0">
                <dt className="text-sm font-medium text-gray-900">{label}</dt>
                <dd className="text-sm text-gray-600">{children}</dd>
            </div>
        </div>
    );
}

function SiteStatus({ status }) {
    const { lastRebuild, lastBackup, offsite, geoip, queuedJobs, failedJobs, warnings } = status;

    return (
        <Section title="Site status" icon={Server}>
            {warnings.length > 0 ? (
                <ul className="mb-3 space-y-2" aria-label="Warnings">
                    {warnings.map((warning) => (
                        <li key={warning} className="flex items-start gap-2 rounded-md border border-warning-300 bg-warning-50 px-3 py-2 text-sm text-warning-900">
                            <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                            <span>
                                <span className="font-semibold">Warning: </span>
                                {warning}
                            </span>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="mb-3 flex items-center gap-2 text-sm text-gray-700">
                    <CircleCheck className="h-4 w-4 text-success-700" aria-hidden="true" />
                    No problems found.
                </p>
            )}
            <dl className="divide-y divide-gray-100">
                <StatusRow icon={RefreshCw} label="Last public site rebuild">
                    {lastRebuild === null
                        ? 'No data'
                        : lastRebuild.at
                          ? `${lastRebuild.status === 'complete' ? 'Completed' : 'Failed'} ${formatDateTime(lastRebuild.at)}`
                          : lastRebuild.line}
                </StatusRow>
                <StatusRow icon={Database} label="Last database backup">
                    {lastBackup === null ? 'No data' : `${formatDateTime(lastBackup.at)} (${formatAge(lastBackup.age_hours)})`}
                    <br />
                    {!offsite.configured
                        ? 'Off-server copies: not configured'
                        : offsite.lastUploadAt
                          ? `Off-server copy: last uploaded ${formatAge(offsite.ageHours)}`
                          : 'Off-server copy: no upload recorded yet'}
                </StatusRow>
                <StatusRow icon={Globe} label="Visitor countries (GeoIP database)">
                    {!geoip.present
                        ? 'Missing: visits are recorded without a country'
                        : !geoip.readable
                          ? "Can't be read: visits are recorded without a country"
                          : `Updated ${formatDateTime(geoip.updatedAt)} (${formatAge(geoip.ageHours)})`}
                </StatusRow>
                <StatusRow icon={Server} label="Background jobs">
                    {queuedJobs === null ? 'No data' : `${queuedJobs} queued`}
                    {', '}
                    {failedJobs === null ? 'failed: no data' : `${failedJobs} failed`}
                </StatusRow>
            </dl>
            {/* Always shown, and not a warning: there is nothing to fix (docs/ARCHITECTURE.md, "Tracker blockers"). */}
            <p className="mt-3 flex items-start gap-2 border-t border-gray-100 pt-3 text-xs text-gray-500">
                <Info className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                <span>
                    Visitor counts may undercount real traffic: some visitors use tracker-blocking tools, and their visits
                    can&rsquo;t be recorded. This is by design.
                </span>
            </p>
        </Section>
    );
}

export default function Dashboard({ userName, publicSiteUrl, content, submissions, visitors, topContent, sources, siteStatus }) {
    // visitors, topContent, sources and siteStatus are only sent to admins.
    const isAdminView = visitors !== undefined;

    return (
        <AuthenticatedLayout header={<Header userName={userName} publicSiteUrl={publicSiteUrl} unread={submissions.unread} />}>
            <Head title="Dashboard" />

            <div className="py-8">
                <div className="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-3 lg:px-8">
                    {isAdminView && (
                        <>
                            <Visitors summary={visitors} />
                            <SiteStatus status={siteStatus} />
                            <TopContent rows={topContent} days={visitors.days} />
                            <Sources rows={sources} days={visitors.days} />
                        </>
                    )}
                    <ContentStatus content={content} />
                    <Submissions submissions={submissions} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
