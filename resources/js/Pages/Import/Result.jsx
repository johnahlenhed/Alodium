import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

function Stat({ label, value }) {
    return (
        <div className="bg-white p-6 shadow sm:rounded-lg">
            <div className="text-sm font-medium text-gray-500">{label}</div>
            <div className="mt-2 text-3xl font-semibold text-gray-900">{value}</div>
        </div>
    );
}

export default function Result({ results }) {
    const { imported_posts, imported_pages, skipped, errors, found = {} } = results;
    const foundSummary = Object.entries(found)
        .map(([type, count]) => `${count} × ${type}`)
        .join(', ');

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
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
                        <div className="rounded-md border border-red-200 bg-red-50 p-6">
                            <h3 className="text-sm font-semibold text-red-800">
                                Errors ({errors.length})
                            </h3>
                            <ul className="mt-2 list-disc space-y-1 ps-5 text-sm text-red-700">
                                {errors.map((error, i) => (
                                    <li key={i}>{error}</li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {skipped.length > 0 && (
                        <div className="overflow-hidden bg-white shadow sm:rounded-lg">
                            <h3 className="px-6 pt-6 text-sm font-semibold text-gray-900">Skipped items</h3>
                            <table className="mt-4 min-w-full divide-y divide-gray-200 text-sm">
                                <thead className="bg-gray-50">
                                    <tr className="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                        <th className="px-6 py-3">Title</th>
                                        <th className="px-6 py-3">Type</th>
                                        <th className="px-6 py-3">Reason</th>
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
                    )}

                    <div className="flex flex-wrap items-center gap-4 text-sm">
                        <span className="text-gray-600">Review the imported content:</span>
                        <Link href={route('posts.index')} className="text-indigo-600 hover:text-indigo-900">
                            Posts →
                        </Link>
                        <Link href={route('pages.index')} className="text-indigo-600 hover:text-indigo-900">
                            Pages →
                        </Link>
                        <Link href={route('import.create')} className="ms-auto text-gray-600 hover:text-gray-900">
                            Import another file
                        </Link>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
