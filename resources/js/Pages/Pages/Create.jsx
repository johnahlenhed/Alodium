import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { toUtcIso } from '@/utils/publishing';
import { Head, useForm } from '@inertiajs/react';
import PageForm from './Partials/PageForm';

export default function Create() {
    const form = useForm({
        title: '',
        content: '',
        status: 'draft',
        publish_at: '',
        meta_title: '',
        meta_description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            publish_at: data.status === 'scheduled' ? toUtcIso(data.publish_at) : null,
        }));
        form.post(route('pages.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    New page
                </h2>
            }
        >
            <Head title="New page" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <PageForm form={form} onSubmit={submit} submitLabel="Create page" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
