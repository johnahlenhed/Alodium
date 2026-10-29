import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

const formatDate = (value) => new Date(value).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' });

export default function Show({ submission }) {
    const isAdmin = usePage().props.auth.user.role === 'admin';
    const reply = `mailto:${submission.email}?subject=${encodeURIComponent('Re: your message')}`;

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Message from {submission.name}
                </h2>
            }
        >
            <Head title="Submission" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl space-y-4 sm:px-6 lg:px-8">
                    <Link href={route('submissions.index')} className="px-4 text-sm text-brand-600 hover:text-brand-900 sm:px-0">
                        ← All submissions
                    </Link>

                    <article className="bg-surface p-4 shadow sm:rounded-lg sm:p-8">
                        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[auto_1fr]">
                            <dt className="font-medium text-gray-500">From</dt>
                            <dd className="text-gray-900">{submission.name}</dd>
                            <dt className="font-medium text-gray-500">Email</dt>
                            <dd className="text-gray-900">{submission.email}</dd>
                            <dt className="font-medium text-gray-500">Received</dt>
                            <dd className="text-gray-900">{formatDate(submission.created_at)}</dd>
                        </dl>

                        {/* Plain text from a visitor: escaped by React, line breaks kept with CSS. Never HTML. */}
                        <p className="mt-6 whitespace-pre-wrap break-words border-t border-gray-100 pt-6 text-gray-900">
                            {submission.message}
                        </p>

                        <div className="mt-8 flex flex-wrap items-center gap-4 border-t border-gray-100 pt-6">
                            <a
                                href={reply}
                                className="inline-flex items-center rounded-md bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-widest text-on-primary hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                            >
                                Reply by email
                            </a>
                            <Link
                                href={route('submissions.unread', submission.id)}
                                method="patch"
                                as="button"
                                className="text-sm text-gray-700 underline hover:text-gray-900"
                            >
                                Mark as unread
                            </Link>
                            {isAdmin && (
                                <Link
                                    href={route('submissions.destroy', submission.id)}
                                    method="delete"
                                    as="button"
                                    onBefore={() => confirm(`Delete the message from ${submission.name}? This cannot be undone.`)}
                                    className="ms-auto text-sm text-danger-600 hover:text-danger-900"
                                >
                                    Delete
                                </Link>
                            )}
                        </div>
                    </article>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
