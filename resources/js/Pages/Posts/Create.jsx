import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import PostForm from './Partials/PostForm';

export default function Create() {
    const form = useForm({
        title: '',
        content: '',
        status: 'draft',
        meta_title: '',
        meta_description: '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.post(route('posts.store'));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    New post
                </h2>
            }
        >
            <Head title="New post" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <PostForm form={form} onSubmit={submit} submitLabel="Create post" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
