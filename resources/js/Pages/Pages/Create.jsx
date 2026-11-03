import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { toUtcIso } from '@/utils/publishing';
import { Head, useForm } from '@inertiajs/react';
import PageForm from './Partials/PageForm';

export default function Create({ blocks = [], frontPage = null }) {
    // New pages are block pages.
    const form = useForm({
        title: '',
        blocks,
        status: 'draft',
        publish_at: '',
        meta_title: '',
        meta_description: '',
        og_image: '',
        // Ticked for a site's first page (admins only; editors get no option).
        make_front_page: frontPage?.suggest ?? false,
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            publish_at: data.status === 'scheduled' ? toUtcIso(data.publish_at) : null,
            make_front_page: frontPage ? data.make_front_page : undefined,
        }));
        form.post(route('pages.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-page-title font-semibold text-gray-800">
                    New page
                </h2>
            }
        >
            <Head title="New page" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-surface p-4 shadow sm:rounded-lg sm:p-8">
                    <PageForm form={form} onSubmit={submit} submitLabel="Create page" mode="blocks" frontPage={frontPage} />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
