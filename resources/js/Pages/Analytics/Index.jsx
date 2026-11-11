import VisitsChart from '@/Components/VisitsChart';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import CampaignLinkBuilder from './Partials/CampaignLinkBuilder';

const RANGES = [7, 30, 90];

function MetricCard({ label, value }) {
    return (
        <div className="bg-surface p-6 shadow sm:rounded-lg">
            <div className="text-sm font-medium text-gray-500">{label}</div>
            <div className="mt-2 text-3xl font-semibold text-gray-900">{value}</div>
        </div>
    );
}

function TableCard({ title, keyLabel, rows, empty = 'No data yet.' }) {
    return (
        <div className="bg-surface p-6 shadow sm:rounded-lg">
            <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
            {rows.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500">{empty}</p>
            ) : (
                <table className="mt-4 w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs uppercase tracking-wider text-gray-500">
                            <th className="pb-2 font-medium">{keyLabel}</th>
                            <th className="pb-2 text-right font-medium">Visits</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-100">
                        {rows.map((row) => (
                            <tr key={row.label}>
                                <td className="max-w-0 truncate py-2 pe-4 text-gray-700" title={row.label}>
                                    {row.label}
                                </td>
                                <td className="py-2 text-right tabular-nums text-gray-900">
                                    {row.count}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
        </div>
    );
}

const NOT_SET = <span className="text-gray-500">not set</span>;

/**
 * The page a campaign's views landed on most: a link to its edit screen when it
 * is a post or a page (as the dashboard's Top content links them), otherwise the
 * path; then how many other pages it landed on, as the import page's "and N more".
 */
function CampaignPage({ row }) {
    if (!row.page) return <span className="text-gray-500">—</span>;
    const others = row.otherPages ?? 0;

    return (
        <>
            <div className="truncate" title={row.page}>
                {row.editUrl ? (
                    <Link href={row.editUrl} className="font-medium text-brand-700 hover:underline">
                        {row.page}
                    </Link>
                ) : (
                    <span className="text-gray-700">{row.page}</span>
                )}
            </div>
            {others > 0 && (
                <div className="text-xs text-gray-600">
                    and {others} more {others === 1 ? 'page' : 'pages'}
                </div>
            )}
        </>
    );
}

/** Views per campaign, with the source and medium each came with and the page they landed on (VisitStatistics::campaigns()). */
function CampaignsCard({ rows, className = '' }) {
    return (
        <div className={`bg-surface p-6 shadow sm:rounded-lg ${className}`}>
            <h3 className="text-sm font-semibold text-gray-900">Campaigns</h3>
            {rows.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500">
                    No visits from campaign links in this period. Make one with the campaign link builder below.
                </p>
            ) : (
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="text-left text-xs uppercase tracking-wider text-gray-500">
                                <th scope="col" className="pb-2 font-medium">Campaign</th>
                                <th scope="col" className="pb-2 font-medium">Source</th>
                                <th scope="col" className="pb-2 font-medium">Medium</th>
                                <th scope="col" className="pb-2 font-medium">Page</th>
                                <th scope="col" className="pb-2 text-right font-medium">Visits</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-gray-100">
                            {rows.map((row) => (
                                <tr key={`${row.campaign}|${row.source}|${row.medium}`}>
                                    <td className="max-w-0 truncate py-2 pe-4 text-gray-900" title={row.campaign}>
                                        {row.campaign}
                                    </td>
                                    <td className="max-w-0 truncate py-2 pe-4 text-gray-700" title={row.source ?? undefined}>
                                        {row.source ?? NOT_SET}
                                    </td>
                                    <td className="max-w-0 truncate py-2 pe-4 text-gray-700" title={row.medium ?? undefined}>
                                        {row.medium ?? NOT_SET}
                                    </td>
                                    <td className="max-w-0 py-2 pe-4">
                                        <CampaignPage row={row} />
                                    </td>
                                    <td className="py-2 text-right tabular-nums text-gray-900">{row.count}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

export default function Index({
    totalVisits,
    avgDuration,
    avgScrollDepth,
    visitsOverTime,
    topPages,
    topSources,
    topReferrers,
    deviceBreakdown,
    topCountries,
    campaigns = [],
    topMediums = [],
    publicSiteUrl = null,
    days,
}) {
    const changeRange = (value) =>
        router.get(route('analytics.index'), { days: value }, { preserveScroll: true, preserveState: true });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-page-title font-semibold text-gray-800">
                        Analytics
                    </h2>
                    <div className="inline-flex rounded-md shadow-sm" role="group" aria-label="Date range">
                        {RANGES.map((range) => (
                            <button
                                key={range}
                                type="button"
                                onClick={() => changeRange(range)}
                                aria-pressed={days === range}
                                className={
                                    'border border-gray-300 px-3 py-1.5 text-sm font-medium first:rounded-s-md last:rounded-e-md ' +
                                    (days === range
                                        ? 'bg-primary text-on-primary'
                                        : 'bg-surface text-gray-700 hover:bg-gray-50')
                                }
                            >
                                {range} days
                            </button>
                        ))}
                    </div>
                </div>
            }
        >
            <Head title="Analytics" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="grid grid-cols-1 gap-6 sm:grid-cols-3">
                        <MetricCard label="Total visits" value={totalVisits} />
                        <MetricCard label="Avg duration" value={`${avgDuration} s`} />
                        <MetricCard label="Avg scroll depth" value={`${avgScrollDepth}%`} />
                    </div>

                    <div className="bg-surface p-6 shadow sm:rounded-lg">
                        <h3 className="text-sm font-semibold text-gray-900">Visits over time</h3>
                        {totalVisits === 0 ? (
                            <p className="mt-4 text-sm text-gray-500">No data yet.</p>
                        ) : (
                            <div className="mt-4">
                                <VisitsChart data={visitsOverTime} label="Visits per day" unit="Visits" />
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                        <TableCard title="Top pages" keyLabel="Page" rows={topPages} />
                        <TableCard title="Traffic sources" keyLabel="Source" rows={topSources} />
                        <TableCard title="Top referrers" keyLabel="Referrer" rows={topReferrers} />
                        <TableCard title="Devices" keyLabel="Device" rows={deviceBreakdown} />
                        <TableCard title="Countries" keyLabel="Country" rows={topCountries} />
                    </div>

                    {/* From campaign (UTM) links: utm_campaign, utm_source and utm_medium only (App\Support\UtmValue). */}
                    <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                        <CampaignsCard rows={campaigns} className="lg:col-span-2" />
                        <TableCard title="Mediums" keyLabel="Medium" rows={topMediums} empty="No visits from campaign links in this period." />
                    </div>

                    <CampaignLinkBuilder publicSiteUrl={publicSiteUrl} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
