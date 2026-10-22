import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronDown, ChevronRight, ChevronUp, GripVertical, Puzzle, Trash } from 'lucide-react';
import { memo, useCallback, useEffect, useRef } from 'react';
import { blockLabel, blockSummary, getBlockType } from './blockTypes';

const EMPTY = {};

const iconButton =
    'inline-flex h-8 w-8 items-center justify-center rounded text-gray-500 hover:bg-gray-100 hover:text-gray-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent';

/**
 * One block in the editor: a header (drag handle, type, summary, collapse,
 * move up/down, delete) and, when expanded, the type's editor. Memoised and
 * given stable callbacks, so typing in one block doesn't re-render the others.
 * The editor (and its TipTap instance) is only mounted while expanded; the data
 * lives in the form state, so collapsing loses nothing.
 */
function BlockCard({ block, index, total, expanded, errors = EMPTY, warning = null, focusRequest, onChange, onToggle, onMove, onRemove }) {
    const type = getBlockType(block.type);
    const Icon = type?.icon ?? Puzzle;
    const label = blockLabel(block);
    const position = `position ${index + 1} of ${total}`;
    const bodyId = `block-${block.id}-body`;

    const { attributes, listeners, setNodeRef, setActivatorNodeRef, transform, transition, isDragging } = useSortable({ id: block.id });

    const refs = { handle: useRef(null), up: useRef(null), down: useRef(null), body: useRef(null) };
    const setHandleRef = useCallback(
        (node) => {
            refs.handle.current = node;
            setActivatorNodeRef(node);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [setActivatorNodeRef],
    );

    // Focus requests from the list: after a move (keep focus on the button used),
    // after adding (the new block's first field), or after a delete (the neighbour).
    useEffect(() => {
        if (!focusRequest) return;
        const frame = requestAnimationFrame(() => {
            if (focusRequest.target === 'first-field') {
                refs.body.current?.querySelector('input, textarea, button, [contenteditable="true"]')?.focus();
                return;
            }
            const target = refs[focusRequest.target]?.current;
            // An up/down button that is now disabled (at the end) can't take focus: use the handle.
            (target && !target.disabled ? target : refs.handle.current)?.focus();
        });
        return () => cancelAnimationFrame(frame);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [focusRequest]);

    const change = useCallback((patch) => onChange(block.id, patch), [block.id, onChange]);

    // Errors the editor doesn't show next to a field (e.g. "type", "id") appear on the card.
    // A nested key such as "data.columns.0.image" belongs to its field ("columns").
    const fields = (type?.fields ?? []).map((field) => `data.${field}`);
    const cardErrors = Object.entries(errors).filter(
        ([key]) => !fields.some((field) => key === field || key.startsWith(`${field}.`)),
    );

    return (
        <li
            ref={setNodeRef}
            style={{ transform: CSS.Transform.toString(transform), transition }}
            className={`rounded-lg border bg-white ${errors !== EMPTY && Object.keys(errors).length ? 'border-red-300' : 'border-gray-200'} ${isDragging ? 'opacity-40' : ''}`}
        >
            <div className="flex items-center gap-1 px-2 py-2">
                <button
                    type="button"
                    ref={setHandleRef}
                    {...attributes}
                    {...listeners}
                    aria-label={`Reorder ${label} block, ${position}`}
                    className={`${iconButton} cursor-grab touch-none active:cursor-grabbing`}
                >
                    <GripVertical className="h-4 w-4" aria-hidden="true" />
                </button>

                <button
                    type="button"
                    onClick={() => onToggle(block.id)}
                    aria-expanded={expanded}
                    aria-controls={bodyId}
                    className="flex min-w-0 flex-1 items-center gap-2 rounded px-1 py-1 text-left hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500"
                >
                    {expanded ? (
                        <ChevronDown className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                    ) : (
                        <ChevronRight className="h-4 w-4 shrink-0 text-gray-400" aria-hidden="true" />
                    )}
                    <Icon className="h-4 w-4 shrink-0 text-gray-500" aria-hidden="true" />
                    <span className="shrink-0 text-sm font-medium text-gray-900">{label}</span>
                    <span className="truncate text-sm text-gray-500">{blockSummary(block)}</span>
                    <span className="sr-only">{`, ${position}, ${expanded ? 'expanded' : 'collapsed'}`}</span>
                </button>

                <button type="button" ref={refs.up} onClick={() => onMove(block.id, -1)} disabled={index === 0} aria-label={`Move ${label} block up`} className={iconButton}>
                    <ChevronUp className="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" ref={refs.down} onClick={() => onMove(block.id, 1)} disabled={index === total - 1} aria-label={`Move ${label} block down`} className={iconButton}>
                    <ChevronDown className="h-4 w-4" aria-hidden="true" />
                </button>
                <button type="button" onClick={() => onRemove(block.id)} aria-label={`Delete ${label} block`} className={`${iconButton} hover:text-red-600`}>
                    <Trash className="h-4 w-4" aria-hidden="true" />
                </button>
            </div>

            {warning && (
                <p className="mx-4 mb-2 rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-800">{warning}</p>
            )}

            {cardErrors.length > 0 && (
                <ul className="mx-4 mb-2 list-disc space-y-1 ps-5 text-sm text-red-600">
                    {cardErrors.map(([key, message]) => (
                        <li key={key}>{message}</li>
                    ))}
                </ul>
            )}

            {expanded && (
                <div id={bodyId} ref={refs.body} className="border-t border-gray-100 px-4 py-4">
                    {type ? (
                        <type.Editor blockId={block.id} data={block.data ?? EMPTY} errors={errors} onChange={change} isFirst={index === 0} />
                    ) : (
                        <p className="text-sm text-gray-600">
                            This block type (<code>{block.type}</code>) can't be edited here yet. It is kept exactly as it
                            is, and you can still move or delete it.
                        </p>
                    )}
                </div>
            )}
        </li>
    );
}

export default memo(BlockCard);
