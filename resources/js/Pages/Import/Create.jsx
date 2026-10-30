import Checkbox from '@/Components/Checkbox';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { formatMegabytes, statusLabel, uploadProblem } from '@/utils/importRun';
import { Head, Link, useForm } from '@inertiajs/react';

const dateFormat = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' });

function RecentRuns({ runs }) {
    if (runs.length === 0) return null;

    return (
        <section aria-labelledby="recent-imports" className="mx-auto max-w-3xl overflow-hidden bg-surface shadow sm:rounded-lg">
            <h3 id="recent-imports" className="px-6 pt-6 text-sm font-semibold text-gray-900">
                Recent imports
            </h3>
            {/* relative: the table's sr-only texts are absolutely positioned and would otherwise escape this scroll box and widen the page. */}
            <div className="relative mt-4 overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                    <thead className="bg-gray-50">
                        <tr className="text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                            <th scope="col" className="px-4 py-3">Date</th>
                            <th scope="col" className="px-4 py-3">By</th>
                            <th scope="col" className="px-4 py-3">Status</th>
                            <th scope="col" className="px-4 py-3 text-right">Posts</th>
                            <th scope="col" className="px-4 py-3 text-right">Pages</th>
                            <th scope="col" className="px-4 py-3 text-right">Images downloaded</th>
                            <th scope="col" className="px-4 py-3 text-right">Images skipped</th>
                            <th scope="col" className="px-4 py-3"><span className="sr-only">Details</span></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-gray-200">
                        {runs.map((run) => (
                            <tr key={run.id}>
                                <td className="whitespace-nowrap px-4 py-3 text-gray-900">
                                    {run.started_at ? dateFormat.format(new Date(run.started_at)) : '—'}
                                </td>
                                <td className="px-4 py-3 text-gray-600">{run.user ?? '—'}</td>
                                <td className="whitespace-nowrap px-4 py-3 text-gray-600">{statusLabel(run.status)}</td>
                                <td className="px-4 py-3 text-right text-gray-600">{run.imported_posts}</td>
                                <td className="px-4 py-3 text-right text-gray-600">{run.imported_pages}</td>
                                {run.download_images ? (
                                    <>
                                        <td className="px-4 py-3 text-right text-gray-600">{run.images_downloaded}</td>
                                        <td className="px-4 py-3 text-right text-gray-600">{run.images_skipped}</td>
                                    </>
                                ) : (
                                    <td colSpan={2} className="px-4 py-3 text-right text-gray-500">Not copied</td>
                                )}
                                <td className="px-4 py-3 text-right">
                                    <Link
                                        href={route('import.runs.show', run.id)}
                                        className="rounded text-brand-600 hover:text-brand-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                                    >
                                        View<span className="sr-only"> import {run.id}</span>
                                    </Link>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

export default function Create({ limits, recentRuns = [] }) {
    const { data, setData, post, processing, progress, errors } = useForm({ file: null, download_images: true });
    const sizeProblem = uploadProblem(data.file, limits.max_upload_bytes);

    const submit = (e) => {
        e.preventDefault();
        if (sizeProblem) return;
        post(route('import.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-page-title font-semibold text-gray-800">
                    Import from WordPress
                </h2>
            }
        >
            <Head title="Import from WordPress" />

            <div className="space-y-6 py-12">
                <div className="mx-auto max-w-3xl space-y-6 bg-surface p-4 shadow sm:rounded-lg sm:p-8">
                    <div className="space-y-2 text-sm text-gray-700">
                        <p>
                            Export your WordPress content from{' '}
                            <strong>Tools &gt; Export &gt; All content</strong>, then upload the
                            XML file here.
                        </p>
                        <p>
                            Published posts and pages stay published, drafts stay drafts. Items
                            that already exist (same slug) are skipped, so you can safely run the
                            import again.
                        </p>
                    </div>

                    <div className="rounded-md border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800">
                        Plugin functionality is not imported — content only. WordPress&apos;s own
                        shortcodes (galleries, captions, audio, video, playlists, embeds) become
                        images and links; other shortcodes stay as text. HTML is cleaned to basic
                        formatting. The result page lists every post or page where something was
                        converted or lost.
                    </div>

                    <form onSubmit={submit} className="space-y-6">
                        <div>
                            <InputLabel htmlFor="file" value={`WordPress export file (.xml, up to ${formatMegabytes(limits.max_upload_bytes)})`} />
                            <input
                                id="file"
                                type="file"
                                accept=".xml,text/xml,application/xml"
                                disabled={processing}
                                aria-describedby={sizeProblem || errors.file ? 'file-error' : undefined}
                                aria-invalid={sizeProblem || errors.file ? true : undefined}
                                onChange={(e) => setData('file', e.target.files[0] ?? null)}
                                className="mt-1 block w-full rounded-md text-sm text-gray-700 file:me-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-gray-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            />
                            <InputError id="file-error" message={sizeProblem ?? errors.file} className="mt-2" />
                        </div>

                        <div>
                            <div className="flex items-start gap-3">
                                <Checkbox
                                    id="download_images"
                                    className="mt-0.5"
                                    checked={data.download_images}
                                    disabled={processing}
                                    aria-describedby="download_images_help"
                                    onChange={(e) => setData('download_images', e.target.checked)}
                                />
                                <InputLabel htmlFor="download_images" value="Download images from the old site" />
                            </div>
                            <div id="download_images_help" className="ms-7 mt-2 space-y-2 text-sm text-gray-600">
                                <p>
                                    Images used in posts, pages and as featured images are copied to
                                    this site, so it no longer depends on the old one. Only images
                                    from the exported site's own address are copied; images hosted
                                    elsewhere stay where they are.
                                </p>
                                <p>
                                    This runs in the background after the upload. It can take a few
                                    minutes for large sites and needs the queue worker to be running.
                                    Limits: {formatMegabytes(limits.max_image_bytes)} per image,{' '}
                                    {limits.max_megapixels} megapixels, up to {limits.max_images_per_run} images
                                    per import.
                                </p>
                                {!data.download_images && (
                                    <p className="font-medium text-gray-700">
                                        Unchecked: only the text is imported. Images keep pointing to
                                        the old site, and featured images are not copied either.
                                    </p>
                                )}
                            </div>
                            <InputError message={errors.download_images} className="mt-2" />
                        </div>

                        {processing && (
                            <div className="space-y-2" role="status">
                                <div className="h-2 overflow-hidden rounded bg-gray-200">
                                    <div
                                        className="h-full bg-primary transition-all"
                                        style={{ width: `${progress?.percentage ?? 100}%` }}
                                    />
                                </div>
                                <p className="text-sm text-gray-600">
                                    {progress && progress.percentage < 100
                                        ? `Uploading… ${progress.percentage}%`
                                        : 'Importing the content. Keep this tab open.'}
                                </p>
                            </div>
                        )}

                        <PrimaryButton disabled={processing || !data.file || Boolean(sizeProblem)}>
                            {processing ? 'Importing…' : 'Import WordPress content'}
                        </PrimaryButton>
                    </form>
                </div>

                <RecentRuns runs={recentRuns} />
            </div>
        </AuthenticatedLayout>
    );
}
