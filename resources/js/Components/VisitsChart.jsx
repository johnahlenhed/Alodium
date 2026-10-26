import {
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/**
 * Daily page views as a line chart (shared by Analytics and the dashboard).
 * The chart is an image with an aria-label; the same numbers are in a visually
 * hidden table for screen readers. `data`: [{ date: 'YYYY-MM-DD', count }].
 */
export default function VisitsChart({ data, label = 'Page views per day', unit = 'Page views', height = 'h-72' }) {
    const total = data.reduce((sum, day) => sum + day.count, 0);

    return (
        <>
            <div
                className={height}
                role="img"
                aria-label={`${label}: ${total} in ${data.length} days, from ${data[0]?.date ?? '-'} to ${data.at(-1)?.date ?? '-'}.`}
            >
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart data={data}>
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
                        <Tooltip formatter={(value) => [value, unit]} labelFormatter={(value) => value} />
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
            <table className="sr-only">
                <caption>{label}</caption>
                <thead>
                    <tr>
                        <th scope="col">Date</th>
                        <th scope="col">{unit}</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((day) => (
                        <tr key={day.date}>
                            <td>{day.date}</td>
                            <td>{day.count}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
}
