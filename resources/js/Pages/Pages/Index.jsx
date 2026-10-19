import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { displayStatus, formatDateTime, STATUS_BADGE_CLASSES } from '@/utils/publishing';
import { Head, Link } from '@inertiajs/react';

const formatDate = (value) =>
    value ? new Date(value).toLocaleDateString('sv-SE') : '—';

export default function Index({ pages }) {
    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-gray-800">
                        Pages
                    </h2>
                    <Link
                        href={route('pages.create')}
                        className="inline-flex items-center rounded-md bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700"
                    >
                        New page
                    </Link>
                </div>
            }
        >
            <Head title="Pages" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    <th className="px-6 py-3">Title</th>
                                    <th className="px-6 py-3">Status</th>
                                    <th className="px-6 py-3">Author</th>
                                    <th className="px-6 py-3">Published</th>
                                    <th className="px-6 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200 text-sm">
                                {pages.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="px-6 py-8 text-center text-gray-500">
                                            No pages yet.
                                        </td>
                                    </tr>
                                )}
                                {pages.map((page) => (
                                    <tr key={page.id}>
                                        <td className="px-6 py-4 font-medium text-gray-900">
                                            {page.title}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span
                                                className={
                                                    'inline-flex rounded-full px-2 py-1 text-xs font-semibold ' +
                                                    STATUS_BADGE_CLASSES[displayStatus(page)]
                                                }
                                            >
                                                {displayStatus(page)}
                                            </span>
                                            {displayStatus(page) === 'scheduled' && (
                                                <span className="ms-2 text-xs text-gray-500">
                                                    {formatDateTime(page.publish_at)}
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-6 py-4 text-gray-600">
                                            {page.author?.name}
                                        </td>
                                        <td className="px-6 py-4 text-gray-600">
                                            {formatDate(page.published_at)}
                                        </td>
                                        <td className="space-x-4 px-6 py-4 text-right">
                                            <Link
                                                href={route('pages.edit', page.id)}
                                                className="text-indigo-600 hover:text-indigo-900"
                                            >
                                                Edit
                                            </Link>
                                            <Link
                                                href={route('pages.destroy', page.id)}
                                                method="delete"
                                                as="button"
                                                className="text-red-600 hover:text-red-900"
                                                onBefore={() => confirm('Delete this page?')}
                                            >
                                                Delete
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
