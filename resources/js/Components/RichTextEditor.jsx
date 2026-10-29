import Image from '@tiptap/extension-image';
import TextAlign from '@tiptap/extension-text-align';
import { CharacterCount, Placeholder } from '@tiptap/extensions';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import { BubbleMenu } from '@tiptap/react/menus';
import StarterKit from '@tiptap/starter-kit';
import {
    AlignCenter,
    AlignLeft,
    AlignRight,
    Bold,
    Code,
    Heading2,
    Heading3,
    Image as ImageIcon,
    Italic,
    Link as LinkIcon,
    List,
    ListOrdered,
    Minus,
    Quote,
    Redo,
    Strikethrough,
    Underline,
    Undo,
    Unlink,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * Turns "example.com" into "https://example.com". Relative paths, anchors and
 * mailto/tel links are left as they are. Unsafe schemes such as javascript:
 * are rejected by the Link extension itself (setLink() then returns false).
 */
function normalizeUrl(input) {
    const url = input.trim();
    if (url === '' || /^([a-z][a-z0-9+.-]*:|\/|#)/i.test(url)) return url;
    return `https://${url}`;
}

function ToolbarButton({ icon: Icon, label, onClick, active = false, disabled = false }) {
    return (
        <button
            type="button"
            title={label}
            aria-label={label}
            aria-pressed={active}
            disabled={disabled}
            // Keep focus (and therefore the selection) in the editor while clicking.
            onMouseDown={(e) => e.preventDefault()}
            onClick={onClick}
            className={
                'inline-flex h-8 w-8 items-center justify-center rounded transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 ' +
                (active
                    ? 'bg-primary text-on-primary'
                    : 'bg-transparent text-gray-700 hover:bg-gray-100') +
                (disabled ? ' cursor-not-allowed opacity-40 hover:bg-transparent' : '')
            }
        >
            <Icon className="h-4 w-4" aria-hidden="true" />
        </button>
    );
}

function Divider() {
    return <span className="mx-1 h-6 w-px self-center bg-gray-200" aria-hidden="true" />;
}

const inputClass =
    'block w-full rounded-md border-gray-400 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500';

/**
 * Small form shown below the toolbar for links and images. It is a <div>, not a
 * <form>: the editor sits inside the Post/Page <form>, and nested forms are
 * invalid HTML. Enter is handled manually so it never submits the outer form.
 */
function Popover({ fields, values, onChange, onConfirm, onCancel, confirmLabel, error }) {
    const onKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            onConfirm();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            onCancel();
        }
    };

    return (
        <div className="flex flex-wrap items-end gap-2 border-b border-gray-200 bg-gray-50 px-2 py-2">
            {fields.map((field, i) => (
                <label key={field.name} className="min-w-48 flex-1 text-xs font-medium text-gray-600">
                    {field.label}
                    <input
                        type={field.type ?? 'text'}
                        value={values[field.name]}
                        placeholder={field.placeholder}
                        autoFocus={i === 0}
                        onKeyDown={onKeyDown}
                        onChange={(e) => onChange(field.name, e.target.value)}
                        className={`mt-1 ${inputClass}`}
                    />
                </label>
            ))}
            <div className="flex gap-2">
                <button
                    type="button"
                    onClick={onConfirm}
                    className="rounded-md bg-primary px-3 py-2 text-xs font-semibold uppercase tracking-widest text-on-primary hover:bg-primary-hover"
                >
                    {confirmLabel}
                </button>
                <button
                    type="button"
                    onClick={onCancel}
                    className="rounded-md px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-100"
                >
                    Cancel
                </button>
            </div>
            {error && <p className="w-full text-xs text-danger-600">{error}</p>}
        </div>
    );
}

export default function RichTextEditor({ value, onChange }) {
    // The editor is created once; a ref keeps onUpdate calling the latest onChange.
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    // null, or { type: 'link' | 'image', ...field values }
    const [popover, setPopover] = useState(null);
    const [popoverError, setPopoverError] = useState(null);

    const editor = useEditor({
        extensions: [
            // In TipTap v3, StarterKit already includes Link and Underline, so they are
            // configured here rather than registered a second time.
            StarterKit.configure({
                link: {
                    autolink: true,
                    openOnClick: false,
                    defaultProtocol: 'https',
                    HTMLAttributes: { target: '_blank', rel: 'noopener noreferrer' },
                },
            }),
            TextAlign.configure({ types: ['heading', 'paragraph'] }),
            Image.configure({ inline: false }),
            CharacterCount,
            Placeholder.configure({ placeholder: 'Start writing your content here...' }),
        ],
        content: value ?? '',
        editorProps: {
            attributes: { 'aria-label': 'Content', class: 'rich-text-content' },
        },
        onUpdate: ({ editor }) => {
            // An empty editor still contains "<p></p>"; store it as empty instead.
            onChangeRef.current(editor.isEmpty ? '' : editor.getHTML());
        },
    });

    // TipTap v3 does not re-render on every transaction, so the toolbar subscribes
    // to exactly the state it displays.
    const state = useEditorState({
        editor,
        selector: ({ editor: e }) =>
            e && {
                bold: e.isActive('bold'),
                italic: e.isActive('italic'),
                underline: e.isActive('underline'),
                strike: e.isActive('strike'),
                h2: e.isActive('heading', { level: 2 }),
                h3: e.isActive('heading', { level: 3 }),
                bulletList: e.isActive('bulletList'),
                orderedList: e.isActive('orderedList'),
                blockquote: e.isActive('blockquote'),
                alignLeft: e.isActive({ textAlign: 'left' }),
                alignCenter: e.isActive({ textAlign: 'center' }),
                alignRight: e.isActive({ textAlign: 'right' }),
                link: e.isActive('link'),
                code: e.isActive('code'),
                canUndo: e.can().undo(),
                canRedo: e.can().redo(),
                characters: e.storage.characterCount.characters(),
            },
    });

    // Follow external changes to `value` (e.g. a form reset). While typing, value
    // already equals the editor's HTML, so this is a no-op and causes no loop.
    useEffect(() => {
        if (!editor) return;
        const current = editor.isEmpty ? '' : editor.getHTML();
        if ((value ?? '') !== current) {
            editor.commands.setContent(value ?? '', { emitUpdate: false });
        }
    }, [editor, value]);

    if (!editor || !state) return null;

    const run = (command) => () => command(editor.chain().focus()).run();

    const closePopover = () => {
        setPopover(null);
        setPopoverError(null);
        editor.commands.focus();
    };

    const openLink = () => {
        const { empty } = editor.state.selection;
        setPopoverError(null);
        setPopover({
            type: 'link',
            // Inside an existing link the whole link is edited, so no label is needed.
            needsLabel: empty && !editor.isActive('link'),
            url: editor.getAttributes('link').href ?? '',
            label: '',
        });
    };

    const confirmLink = () => {
        const href = normalizeUrl(popover.url);
        if (href === '') {
            setPopoverError('Enter a URL.');
            return;
        }

        const ok = popover.needsLabel
            ? editor
                  .chain()
                  .focus()
                  .insertContent({
                      type: 'text',
                      text: popover.label.trim() || href,
                      marks: [{ type: 'link', attrs: { href } }],
                  })
                  .run()
            : editor.chain().focus().extendMarkRange('link').setLink({ href }).run();

        if (!ok) {
            setPopoverError('That URL is not allowed.');
            return;
        }
        closePopover();
    };

    const openImage = () => {
        setPopoverError(null);
        setPopover({ type: 'image', src: '', alt: '' });
    };

    const confirmImage = () => {
        const src = normalizeUrl(popover.src);
        if (!/^(https?:\/\/|\/)/i.test(src)) {
            setPopoverError('Enter an image URL starting with http(s):// or /.');
            return;
        }
        editor.chain().focus().setImage({ src, alt: popover.alt.trim() || null }).run();
        closePopover();
    };

    const setField = (name, fieldValue) => setPopover((p) => ({ ...p, [name]: fieldValue }));

    return (
        <div className="mt-1 rounded-md border border-gray-400 bg-surface shadow-sm focus-within:border-brand-500 focus-within:ring-1 focus-within:ring-brand-500">
            {/* Sticky so the toolbar stays reachable while editing long content. */}
            <div className="sticky top-0 z-10 rounded-t-md border-b border-gray-200 bg-surface">
                <div className="flex flex-wrap items-center gap-0.5 p-1.5" role="toolbar" aria-label="Formatting">
                    <ToolbarButton icon={Bold} label="Bold" active={state.bold} onClick={run((c) => c.toggleBold())} />
                    <ToolbarButton icon={Italic} label="Italic" active={state.italic} onClick={run((c) => c.toggleItalic())} />
                    <ToolbarButton icon={Underline} label="Underline" active={state.underline} onClick={run((c) => c.toggleUnderline())} />
                    <ToolbarButton icon={Strikethrough} label="Strikethrough" active={state.strike} onClick={run((c) => c.toggleStrike())} />
                    <Divider />
                    <ToolbarButton icon={Heading2} label="Heading 2" active={state.h2} onClick={run((c) => c.toggleHeading({ level: 2 }))} />
                    <ToolbarButton icon={Heading3} label="Heading 3" active={state.h3} onClick={run((c) => c.toggleHeading({ level: 3 }))} />
                    <Divider />
                    <ToolbarButton icon={List} label="Bullet list" active={state.bulletList} onClick={run((c) => c.toggleBulletList())} />
                    <ToolbarButton icon={ListOrdered} label="Numbered list" active={state.orderedList} onClick={run((c) => c.toggleOrderedList())} />
                    <ToolbarButton icon={Quote} label="Blockquote" active={state.blockquote} onClick={run((c) => c.toggleBlockquote())} />
                    <Divider />
                    <ToolbarButton icon={AlignLeft} label="Align left" active={state.alignLeft} onClick={run((c) => c.setTextAlign('left'))} />
                    <ToolbarButton icon={AlignCenter} label="Align center" active={state.alignCenter} onClick={run((c) => c.setTextAlign('center'))} />
                    <ToolbarButton icon={AlignRight} label="Align right" active={state.alignRight} onClick={run((c) => c.setTextAlign('right'))} />
                    <Divider />
                    <ToolbarButton icon={LinkIcon} label="Link" active={state.link || popover?.type === 'link'} onClick={openLink} />
                    <ToolbarButton icon={Unlink} label="Remove link" disabled={!state.link} onClick={run((c) => c.extendMarkRange('link').unsetLink())} />
                    <ToolbarButton icon={ImageIcon} label="Image" active={popover?.type === 'image'} onClick={openImage} />
                    <Divider />
                    <ToolbarButton icon={Code} label="Inline code" active={state.code} onClick={run((c) => c.toggleCode())} />
                    <ToolbarButton icon={Minus} label="Horizontal rule" onClick={run((c) => c.setHorizontalRule())} />
                    <Divider />
                    <ToolbarButton icon={Undo} label="Undo" disabled={!state.canUndo} onClick={run((c) => c.undo())} />
                    <ToolbarButton icon={Redo} label="Redo" disabled={!state.canRedo} onClick={run((c) => c.redo())} />
                </div>

                {popover?.type === 'link' && (
                    <Popover
                        fields={[
                            { name: 'url', label: 'URL', placeholder: 'https://example.com' },
                            ...(popover.needsLabel
                                ? [{ name: 'label', label: 'Link text', placeholder: 'Text to show' }]
                                : []),
                        ]}
                        values={popover}
                        onChange={setField}
                        onConfirm={confirmLink}
                        onCancel={closePopover}
                        confirmLabel={state.link ? 'Update link' : 'Add link'}
                        error={popoverError}
                    />
                )}
                {popover?.type === 'image' && (
                    <Popover
                        fields={[
                            { name: 'src', label: 'Image URL', placeholder: 'https://example.com/image.jpg' },
                            { name: 'alt', label: 'Alt text (describes the image)', placeholder: 'Optional' },
                        ]}
                        values={popover}
                        onChange={setField}
                        onConfirm={confirmImage}
                        onCancel={closePopover}
                        confirmLabel="Insert image"
                        error={popoverError}
                    />
                )}
            </div>

            <BubbleMenu
                editor={editor}
                // Only for text selections, not when an image node is selected.
                shouldShow={({ editor: e, from, to }) => from !== to && !e.isActive('image')}
                // Always below the selection: above it, the menu can end up hidden behind the
                // sticky toolbar. TipTap v3 positions menus with Floating UI (the v2
                // `tippyOptions` prop no longer exists); flip is off so it never moves back up.
                options={{ placement: 'bottom', offset: 8, flip: false }}
                className="z-50 flex items-center gap-0.5 rounded-md border border-gray-200 bg-surface p-1 shadow-lg"
            >
                <ToolbarButton icon={Bold} label="Bold" active={state.bold} onClick={run((c) => c.toggleBold())} />
                <ToolbarButton icon={Italic} label="Italic" active={state.italic} onClick={run((c) => c.toggleItalic())} />
                <ToolbarButton icon={Underline} label="Underline" active={state.underline} onClick={run((c) => c.toggleUnderline())} />
                <ToolbarButton icon={LinkIcon} label="Link" active={state.link} onClick={openLink} />
            </BubbleMenu>

            <EditorContent editor={editor} />

            <div className="flex justify-end border-t border-gray-100 px-4 py-1.5 text-xs text-gray-500">
                Characters: {state.characters.toLocaleString('en-US')}
            </div>
        </div>
    );
}
