import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import OgImagePicker from '@/Components/OgImagePicker';
import PrimaryButton from '@/Components/PrimaryButton';
import RichTextEditor from '@/Components/RichTextEditor';
import TextInput from '@/Components/TextInput';
import { localTimeZone, toLocalInput } from '@/utils/publishing';
import BlockEditor from '@/Components/Blocks/BlockEditor';
import { Link } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

const textareaClass =
    'mt-1 block w-full rounded-md border-gray-400 shadow-sm focus:border-brand-500 focus:ring-brand-500';

// Shared by Create and Edit. The parent owns the useForm instance and submit handler.
// mode: "blocks" (block editor) or "classic" (one rich-text editor for `content`,
// for legacy pages). onConvert switches a legacy page to blocks (Edit only).
export default function PageForm({ form, onSubmit, submitLabel, mode = 'classic', onConvert }) {
    const { data, setData, errors, processing } = form;

    // Stable setter for the block list, so block cards keep stable callbacks.
    const setDataRef = useRef(setData);
    setDataRef.current = setData;
    const setBlocks = useCallback(
        (update) => setDataRef.current((current) => ({ ...current, blocks: update(current.blocks) })),
        [],
    );

    // The block order at submit time, to put server errors on the right block.
    const [submittedIds, setSubmittedIds] = useState([]);
    const submit = (e) => {
        if (mode === 'blocks') setSubmittedIds(data.blocks.map((block) => block.id));
        onSubmit(e);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
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

            {mode === 'blocks' ? (
                <div>
                    <InputLabel value="Content blocks" />
                    <div className="mt-2">
                        <BlockEditor blocks={data.blocks} setBlocks={setBlocks} errors={errors} submittedIds={submittedIds} />
                    </div>
                </div>
            ) : (
                <div>
                    {onConvert && (
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-brand-200 bg-brand-50 px-4 py-3">
                            <p className="text-sm text-brand-900">
                                This page uses the classic editor. Convert it to build the page from blocks.
                            </p>
                            <button
                                type="button"
                                onClick={onConvert}
                                className="rounded-md bg-surface px-3 py-1.5 text-sm font-medium text-brand-700 shadow-sm ring-1 ring-brand-200 hover:bg-brand-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                            >
                                Convert to blocks
                            </button>
                        </div>
                    )}
                    <InputLabel value="Content" />
                    <RichTextEditor
                        value={data.content}
                        onChange={(html) => setData('content', html)}
                    />
                    <InputError message={errors.content} className="mt-2" />
                </div>
            )}

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

            <OgImagePicker
                value={data.og_image}
                onChange={(url) => setData('og_image', url)}
                error={errors.og_image}
            />

            <div className="flex items-center gap-4">
                <PrimaryButton disabled={processing}>{submitLabel}</PrimaryButton>
                <Link
                    href={route('pages.index')}
                    className="text-sm text-gray-600 underline hover:text-gray-900"
                >
                    Cancel
                </Link>
            </div>
        </form>
    );
}
