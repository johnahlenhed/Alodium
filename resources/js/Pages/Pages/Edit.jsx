import { convertToBlocks } from '@/Components/Blocks/blocksReducer';
import RevisionHistory from '@/Components/RevisionHistory';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { displayStatus, toLocalInput, toUtcIso } from '@/utils/publishing';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import PageForm from './Partials/PageForm';

const CONVERT_CONFIRMATION =
    'Convert this page to blocks?\n\n'
    + 'The current content becomes one text block. Nothing changes until you save.\n\n'
    + 'After saving, this page uses the block editor and there is no button to switch it back. '
    + 'If the page is published, saving also keeps its current version in the revision history, '
    + 'so you can restore it there. Drafts have no revision history.';

function EditForm({ page, blocks, hasBlocks }) {
    // Block pages open in the block editor; legacy pages (blocks = null) in the classic one.
    const [mode, setMode] = useState(hasBlocks ? 'blocks' : 'classic');

    // Nullable DB columns arrive as null; controlled inputs need strings.
    const form = useForm({
        title: page.title,
        content: page.content ?? '',
        blocks: hasBlocks ? convertToBlocks(blocks) : [],
        // Stored as draft + publish_at; shown as "scheduled" with the time in local time.
        status: displayStatus(page),
        publish_at: toLocalInput(page.publish_at),
        meta_title: page.meta_title ?? '',
        meta_description: page.meta_description ?? '',
        og_image: page.og_image ?? '',
    });

    // `blocks` for a legacy page is Page::blocksOrLegacy(): its content as one text block.
    const convert = () => {
        if (!window.confirm(CONVERT_CONFIRMATION)) return;
        form.setData('blocks', convertToBlocks(blocks));
        setMode('blocks');
    };

    const submit = (e) => {
        e.preventDefault();
        form.transform(({ content, blocks: blockList, ...data }) => ({
            ...data,
            publish_at: data.status === 'scheduled' ? toUtcIso(data.publish_at) : null,
            // Block mode never sends content (the stored content stays untouched);
            // classic mode never sends blocks (the stored blocks stay untouched).
            ...(mode === 'blocks' ? { blocks: blockList } : { content }),
        }));
        form.put(route('pages.update', page.id));
    };

    return (
        <PageForm
            form={form}
            onSubmit={submit}
            submitLabel="Save changes"
            mode={mode}
            onConvert={mode === 'classic' ? convert : undefined}
        />
    );
}

export default function Edit({ page, revisions, blocks, hasBlocks }) {
    return (
        <AuthenticatedLayout
            header={
                <h2 className="text-page-title font-semibold text-gray-800">
                    Edit page
                </h2>
            }
        >
            <Head title="Edit page" />

            <div className="mx-auto max-w-3xl space-y-6 py-12">
                <div className="bg-surface p-4 shadow sm:rounded-lg sm:p-8">
                    {/* useForm keeps its initial data. Keyed on updated_at, so after a
                        revision restore the form starts again from the restored page. */}
                    <EditForm key={page.updated_at} page={page} blocks={blocks} hasBlocks={hasBlocks} />
                </div>

                <RevisionHistory
                    revisions={revisions}
                    restoreRoute="pages.revisions.restore"
                    modelId={page.id}
                />
            </div>
        </AuthenticatedLayout>
    );
}
