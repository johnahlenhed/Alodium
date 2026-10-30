import InputError from '@/Components/InputError';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useRef } from 'react';

const ACCEPT = '.jpg,.jpeg,.png,.gif,.webp,.svg';

const formatDate = (value) => new Date(value).toLocaleDateString('sv-SE');

export default function Index({ media }) {
    // Only admins may delete media (the route enforces it; this hides the button).
    const isAdmin = usePage().props.auth.user.role === 'admin';
    const fileInput = useRef(null);
    const { transform, post, processing, progress, errors, reset } = useForm({ file: null });

    // Upload as soon as a file is picked: no separate submit step needed.
    const upload = (e) => {
        const file = e.target.files[0];
        if (!file) return;

        // setData() only applies on the next render, so it would still send file: null here.
        // transform() is applied synchronously when post() builds the request.
        transform(() => ({ file }));
        post(route('media.store'), {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                reset('file');
                fileInput.current.value = '';
            },
        });
    };

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-page-title font-semibold text-gray-800">
                        Media
                    </h2>
                    <div>
                        <input
                            ref={fileInput}
                            type="file"
                            accept={ACCEPT}
                            className="hidden"
                            onChange={upload}
                        />
                        <button
                            type="button"
                            onClick={() => fileInput.current.click()}
                            disabled={processing}
                            className="inline-flex items-center rounded-md bg-primary px-4 py-2 text-xs font-semibold uppercase tracking-widest text-on-primary hover:bg-primary-hover disabled:opacity-50"
                        >
                            {processing ? 'Uploading…' : 'Upload'}
                        </button>
                    </div>
                </div>
            }
        >
            <Head title="Media" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl space-y-4 sm:px-6 lg:px-8">
                    {progress && (
                        <div className="h-2 overflow-hidden rounded bg-gray-200">
                            <div
                                className="h-full bg-primary transition-all"
                                style={{ width: `${progress.percentage}%` }}
                            />
                        </div>
                    )}
                    <InputError message={errors.file} />

                    {media.length === 0 ? (
                        <div className="bg-surface p-12 text-center shadow sm:rounded-lg">
                            <p className="text-gray-500">No media uploaded yet.</p>
                            <p className="mt-1 text-sm text-gray-500">
                                JPG, PNG, GIF, WebP or SVG, up to 10 MB.
                            </p>
                        </div>
                    ) : (
                        <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                            {media.map((item) => (
                                <li key={item.id} className="overflow-hidden bg-surface shadow sm:rounded-lg">
                                    <a href={item.url} target="_blank" rel="noreferrer">
                                        <img
                                            src={item.thumb_url}
                                            alt={item.name}
                                            loading="lazy"
                                            className="aspect-square w-full bg-gray-100 object-cover"
                                        />
                                    </a>
                                    <div className="space-y-1 p-3 text-xs">
                                        <div className="truncate font-medium text-gray-900" title={item.file_name}>
                                            {item.file_name}
                                        </div>
                                        <div className="flex items-center justify-between text-gray-500">
                                            <span>
                                                {item.human_size} · {formatDate(item.created_at)}
                                            </span>
                                            {isAdmin && (
                                                <Link
                                                    href={route('media.destroy', item.id)}
                                                    method="delete"
                                                    as="button"
                                                    preserveScroll
                                                    className="text-danger-600 hover:text-danger-900"
                                                    onBefore={() => confirm(`Delete ${item.file_name}?`)}
                                                >
                                                    Delete
                                                </Link>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
