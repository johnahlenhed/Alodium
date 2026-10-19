import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { displayStatus, toLocalInput, toUtcIso } from '@/utils/publishing';
import { Head, useForm } from '@inertiajs/react';
import PageForm from './Partials/PageForm';

export default function Edit({ page }) {
    // Nullable DB columns arrive as null; controlled inputs need strings.
    const form = useForm({
        title: page.title,
        content: page.content ?? '',
        // Stored as draft + publish_at; shown as "scheduled" with the time in local time.
        status: displayStatus(page),
        publish_at: toLocalInput(page.publish_at),
        meta_title: page.meta_title ?? '',
        meta_description: page.meta_description ?? '',
        og_image: page.og_image ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            publish_at: data.status === 'scheduled' ? toUtcIso(data.publish_at) : null,
        }));
        form.put(route('pages.update', page.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Edit page
                </h2>
            }
        >
            <Head title="Edit page" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <PageForm form={form} onSubmit={submit} submitLabel="Save changes" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
