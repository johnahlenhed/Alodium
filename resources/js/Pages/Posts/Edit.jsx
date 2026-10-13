import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import PostForm from './Partials/PostForm';

export default function Edit({ post }) {
    // Nullable DB columns arrive as null; controlled inputs need strings.
    const form = useForm({
        title: post.title,
        content: post.content ?? '',
        status: post.status,
        meta_title: post.meta_title ?? '',
        meta_description: post.meta_description ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.put(route('posts.update', post.id));
    };

    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800">
                    Edit post
                </h2>
            }
        >
            <Head title="Edit post" />

            <div className="py-12">
                <div className="mx-auto max-w-3xl bg-white p-4 shadow sm:rounded-lg sm:p-8">
                    <PostForm form={form} onSubmit={submit} submitLabel="Save changes" />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
