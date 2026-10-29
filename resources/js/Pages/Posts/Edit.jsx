import RevisionHistory from '@/Components/RevisionHistory';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { displayStatus, toLocalInput, toUtcIso } from '@/utils/publishing';
import { Head, useForm } from '@inertiajs/react';
import PostForm from './Partials/PostForm';

export default function Edit({ post, revisions }) {
    // Nullable DB columns arrive as null; controlled inputs need strings.
    const form = useForm({
        title: post.title,
        content: post.content ?? '',
        // Stored as draft + publish_at; shown as "scheduled" with the time in local time.
        status: displayStatus(post),
        publish_at: toLocalInput(post.publish_at),
        meta_title: post.meta_title ?? '',
        meta_description: post.meta_description ?? '',
        og_image: post.og_image ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            publish_at: data.status === 'scheduled' ? toUtcIso(data.publish_at) : null,
        }));
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

            <div className="mx-auto max-w-3xl space-y-6 py-12">
                <div className="bg-surface p-4 shadow sm:rounded-lg sm:p-8">
                    <PostForm form={form} onSubmit={submit} submitLabel="Save changes" />
                </div>

                <RevisionHistory
                    revisions={revisions}
                    restoreRoute="posts.revisions.restore"
                    modelId={post.id}
                />
            </div>
        </AuthenticatedLayout>
    );
}
