import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const RANGES = [7, 30, 90];

function MetricCard({ label, value }) {
    return (
        <div className="bg-white p-6 shadow sm:rounded-lg">
            <div className="text-sm font-medium text-gray-500">{label}</div>
            <div className="mt-2 text-3xl font-semibold text-gray-900">{value}</div>
        </div>
    );
}

function TableCard({ title, keyLabel, rows }) {
    return (
        <div className="bg-white p-6 shadow sm:rounded-lg">
            <h3 className="text-sm font-semibold text-gray-900">{title}</h3>
            {rows.length === 0 ? (
                <p className="mt-4 text-sm text-gray-500">No data yet.</p>
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
    days,
}) {
    const changeRange = (value) =>
        router.get(route('analytics.index'), { days: value }, { preserveScroll: true, preserveState: true });

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
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
                                        ? 'bg-gray-800 text-white'
                                        : 'bg-white text-gray-700 hover:bg-gray-50')
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
                    <div className="grid gap-6 sm:grid-cols-3">
                        <MetricCard label="Total visits" value={totalVisits} />
                        <MetricCard label="Avg duration" value={`${avgDuration} s`} />
                        <MetricCard label="Avg scroll depth" value={`${avgScrollDepth}%`} />
                    </div>

                    <div className="bg-white p-6 shadow sm:rounded-lg">
                        <h3 className="text-sm font-semibold text-gray-900">Visits over time</h3>
                        {totalVisits === 0 ? (
                            <p className="mt-4 text-sm text-gray-500">No data yet.</p>
                        ) : (
                            <div className="mt-4 h-72">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={visitsOverTime}>
                                        <CartesianGrid stroke="#e5e7eb" vertical={false} />
                                        <XAxis
                                            dataKey="date"
                                            tickFormatter={(d) => d.slice(5)}
                                            tick={{ fontSize: 12, fill: '#6b7280' }}
                                            tickLine={false}
                                            axisLine={{ stroke: '#e5e7eb' }}
                                            minTickGap={24}
                                        />
                                        <YAxis
                                            allowDecimals={false}
                                            tick={{ fontSize: 12, fill: '#6b7280' }}
                                            tickLine={false}
                                            axisLine={false}
                                            width={32}
                                        />
                                        <Tooltip
                                            formatter={(value) => [value, 'Visits']}
                                            labelFormatter={(label) => label}
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="count"
                                            stroke="#4f46e5"
                                            strokeWidth={2}
                                            dot={false}
                                            activeDot={{ r: 4, stroke: '#fff', strokeWidth: 2 }}
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        )}
                    </div>

                    <div className="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                        <TableCard title="Top pages" keyLabel="Page" rows={topPages} />
                        <TableCard title="Traffic sources" keyLabel="UTM source" rows={topSources} />
                        <TableCard title="Top referrers" keyLabel="Referrer" rows={topReferrers} />
                        <TableCard title="Devices" keyLabel="Device" rows={deviceBreakdown} />
                        <TableCard title="Countries" keyLabel="Country" rows={topCountries} />
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
