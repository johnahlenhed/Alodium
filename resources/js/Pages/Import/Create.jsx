import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';

export default function Create() {
    const { data, setData, post, processing, progress, errors } = useForm({ file: null });

    const submit = (e) => {
        e.preventDefault();
        post(route('import.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Import from WordPress
                </h2>
            }
        >
            <Head title="Import from WordPress" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl space-y-6 bg-white p-4 shadow sm:rounded-lg sm:p-8">
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

                    <div className="rounded-md border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800">
                        Plugin functionality is not imported — content only. Shortcodes are
                        removed and HTML is cleaned to basic formatting.
                    </div>

                    <form onSubmit={submit} className="space-y-6">
                        <div>
                            <InputLabel htmlFor="file" value="WordPress export file (.xml)" />
                            <input
                                id="file"
                                type="file"
                                accept=".xml,text/xml,application/xml"
                                disabled={processing}
                                onChange={(e) => setData('file', e.target.files[0] ?? null)}
                                className="mt-1 block w-full text-sm text-gray-700 file:me-4 file:rounded-md file:border-0 file:bg-gray-100 file:px-4 file:py-2 file:text-sm file:font-medium hover:file:bg-gray-200"
                            />
                            <InputError message={errors.file} className="mt-2" />
                        </div>

                        {processing && (
                            <div className="space-y-2" role="status">
                                <div className="h-2 overflow-hidden rounded bg-gray-200">
                                    <div
                                        className="h-full bg-indigo-600 transition-all"
                                        style={{ width: `${progress?.percentage ?? 100}%` }}
                                    />
                                </div>
                                <p className="text-sm text-gray-600">
                                    {progress && progress.percentage < 100
                                        ? `Uploading… ${progress.percentage}%`
                                        : 'Importing content and downloading images. This can take a few minutes — keep this tab open.'}
                                </p>
                            </div>
                        )}

                        <PrimaryButton disabled={processing || !data.file}>
                            {processing ? 'Importing…' : 'Import WordPress content'}
                        </PrimaryButton>
                    </form>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
