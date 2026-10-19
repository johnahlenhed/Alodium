import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import TextInput from '@/Components/TextInput';
import { localTimeZone, toLocalInput } from '@/utils/publishing';
import { Link } from '@inertiajs/react';

const textareaClass =
    'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';

// Shared by Create and Edit. The parent owns the useForm instance and submit handler.
export default function PostForm({ form, onSubmit, submitLabel }) {
    const { data, setData, errors, processing } = form;

    return (
        <form onSubmit={onSubmit} className="space-y-6">
            <div>
                <InputLabel htmlFor="title" value="Title" />
                <TextInput
                    id="title"
                    className="mt-1 block w-full"
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    isFocused
                />
                <InputError message={errors.title} className="mt-2" />
            </div>

            <div>
                <InputLabel value="Content" />
                <RichTextEditor
                    value={data.content}
                    onChange={(html) => setData('content', html)}
                />
                <InputError message={errors.content} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="status" value="Status" />
                <select
                    id="status"
                    className={textareaClass}
                    value={data.status}
                    onChange={(e) => {
                        const status = e.target.value;
                        // Leaving "Scheduled" discards the chosen time.
                        setData((current) => ({
                            ...current,
                            status,
                            publish_at: status === 'scheduled' ? current.publish_at : '',
                        }));
                    }}
                >
                    <option value="draft">Draft</option>
                    <option value="scheduled">Scheduled</option>
                    <option value="published">Published</option>
                </select>
                <InputError message={errors.status} className="mt-2" />
            </div>

            {data.status === 'scheduled' && (
                <div>
                    <InputLabel htmlFor="publish_at" value="Publish at" />
                    <input
                        id="publish_at"
                        type="datetime-local"
                        className={textareaClass}
                        value={data.publish_at}
                        min={toLocalInput(new Date())}
                        onChange={(e) => setData('publish_at', e.target.value)}
                    />
                    <p className="mt-1 text-sm text-gray-500">
                        Published automatically at this time ({localTimeZone}). Until then it
                        stays a draft and is not shown on the public site.
                    </p>
                    <InputError message={errors.publish_at} className="mt-2" />
                </div>
            )}

            <div>
                <InputLabel htmlFor="meta_title" value="Meta title" />
                <TextInput
                    id="meta_title"
                    className="mt-1 block w-full"
                    value={data.meta_title}
                    onChange={(e) => setData('meta_title', e.target.value)}
                />
                <InputError message={errors.meta_title} className="mt-2" />
            </div>

            <div>
                <InputLabel htmlFor="meta_description" value="Meta description" />
                <textarea
                    id="meta_description"
                    rows={3}
                    className={textareaClass}
                    value={data.meta_description}
                    onChange={(e) => setData('meta_description', e.target.value)}
                />
                <InputError message={errors.meta_description} className="mt-2" />
            </div>

            <div className="flex items-center gap-4">
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
                <Link
                    href={route('posts.index')}
                    className="text-sm text-gray-600 underline hover:text-gray-900"
                >
                    Cancel
                </Link>
            </div>
        </form>
    );
}
