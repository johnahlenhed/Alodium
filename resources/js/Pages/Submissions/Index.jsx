import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';

const formatDate = (value) => new Date(value).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' });

export default function Index({ submissions }) {
    const { data, prev_page_url, next_page_url, current_page, last_page, total } = submissions;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-page-title font-semibold text-gray-800">
                    Submissions
                </h2>
            }
        >
            <Head title="Submissions" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    <p className="px-4 text-sm text-gray-600 sm:px-0">
                        Messages sent through the contact form on the public site, newest first. {total}{' '}
                        {total === 1 ? 'message' : 'messages'} in total.
                    </p>

                    <div className="overflow-hidden bg-surface shadow sm:rounded-lg">
                        <table className="min-w-full divide-y divide-gray-200 text-sm">
                            <thead className="bg-gray-50">
                                <tr className="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    <th className="w-8 px-4 py-3">
                                        <span className="sr-only">Status</span>
                                    </th>
                                    <th className="px-4 py-3">From</th>
                                    <th className="px-4 py-3">Message</th>
                                    <th className="px-4 py-3 text-right">Received</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-200">
                                {data.length === 0 && (
                                    <tr>
                                        <td colSpan={4} className="px-4 py-8 text-center text-gray-500">
                                            No messages yet.
                                        </td>
                                    </tr>
                                )}
                                {data.map((submission) => (
                                    <tr key={submission.id} className={submission.read ? '' : 'bg-brand-50/40'}>
                                        <td className="px-4 py-3">
                                            {!submission.read && (
                                                <span className="block h-2.5 w-2.5 rounded-full bg-primary" aria-hidden="true" />
                                            )}
                                            <span className="sr-only">{submission.read ? 'Read' : 'Unread'}</span>
                                        </td>
                                        <td className="px-4 py-3">
                                            {/* Plain text from visitors: rendered as escaped text, never HTML. */}
                                            <Link
                                                href={route('submissions.show', submission.id)}
                                                className={`text-gray-900 hover:text-brand-700 hover:underline ${submission.read ? '' : 'font-semibold'}`}
                                            >
                                                {submission.name}
                                            </Link>
                                            <div className="text-gray-500">{submission.email}</div>
                                        </td>
                                        <td className="max-w-md truncate px-4 py-3 text-gray-600" title={submission.excerpt}>
                                            {submission.excerpt}
                                        </td>
                                        <td className="whitespace-nowrap px-4 py-3 text-right text-gray-500">
                                            {formatDate(submission.created_at)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {last_page > 1 && (
                        <nav className="flex items-center justify-between px-4 text-sm sm:px-0" aria-label="Pagination">
                            {prev_page_url ? (
                                <Link href={prev_page_url} className="text-brand-600 hover:text-brand-900" preserveScroll>
                                    ← Newer
                                </Link>
                            ) : (
                                <span />
                            )}
                            <span className="text-gray-500">
                                Page {current_page} of {last_page}
                            </span>
                            {next_page_url ? (
                                <Link href={next_page_url} className="text-brand-600 hover:text-brand-900" preserveScroll>
                                    Older →
                                </Link>
                            ) : (
                                <span />
                            )}
                        </nav>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
