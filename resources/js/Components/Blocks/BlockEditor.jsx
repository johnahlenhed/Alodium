import {
    closestCenter,
    DndContext,
    DragOverlay,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { SortableContext, sortableKeyboardCoordinates, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Plus } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import BlockCard from './BlockCard';
import { addBlock, canAdd, errorsByBlockId, hasContent, MAX_BLOCKS, moveBlock, moveBlockBy, removeBlock, updateBlockData } from './blocksReducer';
import { BLOCK_TYPES, blockLabel, blockSummary, getBlockType } from './blockTypes';

/** Cards start expanded on short pages and collapsed on longer ones. */
const EXPANDED_UP_TO = 5;

/**
 * The block list of a page: cards, the add menu, drag and drop, and screen reader
 * announcements. `blocks` lives in the page form; `setBlocks(next)` replaces it.
 * `errors` are the form's errors; `submittedIds` is the block order at the last
 * submit, so server errors ("blocks.2.data.heading") land on the right block.
 */
export default function BlockEditor({ blocks, setBlocks, errors, submittedIds }) {
    // Latest blocks for the stable callbacks below (they never change identity, so
    // memoised cards don't re-render on every keystroke in another card).
    const blocksRef = useRef(blocks);
    blocksRef.current = blocks;

    const [collapsed, setCollapsed] = useState(() => new Set(blocks.length > EXPANDED_UP_TO ? blocks.map((b) => b.id) : []));
    const [focusRequest, setFocusRequest] = useState(null);
    const [message, setMessage] = useState('');
    const [menuOpen, setMenuOpen] = useState(false);
    const [activeId, setActiveId] = useState(null);
    const addButtonRef = useRef(null);

    const errorsById = useMemo(() => errorsByBlockId(errors, submittedIds), [errors, submittedIds]);

    // Optional per-type warnings that depend on the whole page (e.g. two contact
    // forms). Plain strings, so cards whose warning doesn't change don't re-render.
    const warnings = useMemo(() => {
        const result = {};
        for (const block of blocks) {
            const warning = getBlockType(block.type)?.warning?.(block, blocks);
            if (warning) result[block.id] = warning;
        }
        return result;
    }, [blocks]);

    // SortableContext re-renders every card when `items` is a new array, so the id
    // list only changes when the order does (not on every keystroke in a block).
    const order = blocks.map((b) => b.id).join('\n');
    // eslint-disable-next-line react-hooks/exhaustive-deps
    const ids = useMemo(() => blocks.map((b) => b.id), [order]);
    const listError = errors?.blocks;

    // A block with validation errors expands automatically.
    useEffect(() => {
        const withErrors = Object.keys(errorsById);
        if (withErrors.length === 0) return;
        setCollapsed((current) => {
            const next = new Set(current);
            withErrors.forEach((id) => next.delete(id));
            return next;
        });
    }, [errorsById]);

    const describe = (id, list = blocksRef.current) => {
        const index = list.findIndex((b) => b.id === id);
        return `${blockLabel(list[index])} block, position ${index + 1} of ${list.length}`;
    };

    const onChange = useCallback((id, patch) => setBlocks((current) => updateBlockData(current, id, patch)), [setBlocks]);

    const onToggle = useCallback((id) => {
        setCollapsed((current) => {
            const next = new Set(current);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }, []);

    const onMove = useCallback(
        (id, delta) => {
            const next = moveBlockBy(blocksRef.current, id, delta);
            if (next === blocksRef.current) return;
            setBlocks(() => next);
            setMessage(`${describe(id, next)}.`);
            setFocusRequest({ id, target: delta < 0 ? 'up' : 'down' });
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [setBlocks],
    );

    const onRemove = useCallback(
        (id) => {
            const list = blocksRef.current;
            const index = list.findIndex((b) => b.id === id);
            const block = list[index];
            if (hasContent(block.data) && !window.confirm(`Delete this ${blockLabel(block)} block? Its content will be lost when you save.`)) {
                return;
            }
            const next = removeBlock(list, id);
            setBlocks(() => next);
            setMessage(`${blockLabel(block)} block deleted. ${next.length} ${next.length === 1 ? 'block' : 'blocks'} left.`);
            // Focus moves to the block that took its place, the one before it, or the add button.
            const neighbour = next[index] ?? next[index - 1];
            if (neighbour) {
                setFocusRequest({ id: neighbour.id, target: 'handle' });
            } else {
                setFocusRequest(null);
                requestAnimationFrame(() => addButtonRef.current?.focus());
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [setBlocks],
    );

    const add = (type) => {
        const [next, id] = addBlock(blocksRef.current, type, BLOCK_TYPES[type].defaultData);
        setMenuOpen(false);
        if (!id) return;
        setBlocks(() => next);
        setCollapsed((current) => {
            const copy = new Set(current);
            copy.delete(id);
            return copy;
        });
        setMessage(`${BLOCK_TYPES[type].label} block added at position ${next.length} of ${next.length}.`);
        setFocusRequest({ id, target: 'first-field' });
    };

    // Drag and drop: by the handle only (listeners are on the handle), with a small
    // activation distance so clicks on the handle don't start a drag.
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const onDragEnd = ({ active, over }) => {
        setActiveId(null);
        if (!over || active.id === over.id) return;
        const list = blocksRef.current;
        const next = moveBlock(list, active.id, list.findIndex((b) => b.id === over.id));
        setBlocks(() => next);
    };

    const announcements = useMemo(() => ({
        onDragStart: ({ active }) => `Picked up ${describe(active.id)}.`,
        onDragOver: ({ active, over }) =>
            over ? `${blockLabel(blocksRef.current.find((b) => b.id === active.id))} block is over position ${blocksRef.current.findIndex((b) => b.id === over.id) + 1} of ${blocksRef.current.length}.` : undefined,
        onDragEnd: ({ active, over }) =>
            over
                ? `${blockLabel(blocksRef.current.find((b) => b.id === active.id))} block dropped at position ${blocksRef.current.findIndex((b) => b.id === over.id) + 1} of ${blocksRef.current.length}.`
                : `${blockLabel(blocksRef.current.find((b) => b.id === active.id))} block dropped.`,
        onDragCancel: ({ active }) => `Moving cancelled. ${describe(active.id)}.`,
        // Reads the latest blocks through blocksRef, so it can be created once.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }), []);
    const screenReaderInstructions = useMemo(() => ({
        draggable:
            'To reorder, press space or enter on the drag handle, use the up and down arrow keys to move the block, then press space or enter to drop it, or escape to cancel.',
    }), []);
    const accessibility = useMemo(() => ({ announcements, screenReaderInstructions }), [announcements, screenReaderInstructions]);

    const activeBlock = activeId ? blocks.find((b) => b.id === activeId) : null;

    return (
        <div className="space-y-4">
            <div aria-live="polite" className="sr-only">
                {message}
            </div>

            {blocks.length === 0 ? (
                <p className="rounded-lg border border-dashed border-gray-300 px-4 py-8 text-center text-sm text-gray-600">
                    This page has no blocks yet. Add a hero, text or image block to start.
                </p>
            ) : (
                <DndContext
                    sensors={sensors}
                    collisionDetection={closestCenter}
                    onDragStart={({ active }) => setActiveId(active.id)}
                    onDragEnd={onDragEnd}
                    onDragCancel={() => setActiveId(null)}
                    accessibility={accessibility}
                >
                    <SortableContext items={ids} strategy={verticalListSortingStrategy}>
                        <ol className="space-y-3" aria-label="Page blocks">
                            {blocks.map((block, index) => (
                                <BlockCard
                                    key={block.id}
                                    block={block}
                                    index={index}
                                    total={blocks.length}
                                    expanded={!collapsed.has(block.id)}
                                    errors={errorsById[block.id]}
                                    warning={warnings[block.id] ?? null}
                                    focusRequest={focusRequest?.id === block.id ? focusRequest : null}
                                    onChange={onChange}
                                    onToggle={onToggle}
                                    onMove={onMove}
                                    onRemove={onRemove}
                                />
                            ))}
                        </ol>
                    </SortableContext>

                    {/* A one-line summary while dragging, never a second editor. */}
                    <DragOverlay>
                        {activeBlock && (
                            <div className="rounded-lg border border-indigo-300 bg-white px-4 py-3 text-sm shadow-lg">
                                <span className="font-medium text-gray-900">{blockLabel(activeBlock)}</span>{' '}
                                <span className="text-gray-500">{blockSummary(activeBlock)}</span>
                            </div>
                        )}
                    </DragOverlay>
                </DndContext>
            )}

            {listError && <p className="text-sm text-red-600">{listError}</p>}

            <div className="relative">
                <button
                    type="button"
                    ref={addButtonRef}
                    onClick={() => setMenuOpen((open) => !open)}
                    disabled={!canAdd(blocks)}
                    aria-expanded={menuOpen}
                    aria-controls="add-block-menu"
                    className="inline-flex items-center gap-2 rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <Plus className="h-4 w-4" aria-hidden="true" />
                    Add block
                </button>
                {!canAdd(blocks) && (
                    <p className="mt-2 text-sm text-gray-500">A page can have at most {MAX_BLOCKS} blocks.</p>
                )}

                {menuOpen && (
                    <ul
                        id="add-block-menu"
                        className="mt-2 divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm"
                        onKeyDown={(e) => {
                            if (e.key === 'Escape') {
                                setMenuOpen(false);
                                addButtonRef.current?.focus();
                            }
                        }}
                    >
                        {Object.entries(BLOCK_TYPES).map(([type, definition], i) => (
                            <li key={type}>
                                <button
                                    type="button"
                                    autoFocus={i === 0}
                                    onClick={() => add(type)}
                                    className="flex w-full items-start gap-3 px-4 py-3 text-left hover:bg-gray-50 focus:bg-indigo-50 focus:outline-none"
                                >
                                    <definition.icon className="mt-0.5 h-4 w-4 shrink-0 text-gray-500" aria-hidden="true" />
                                    <span>
                                        <span className="block text-sm font-medium text-gray-900">{definition.label}</span>
                                        <span className="block text-sm text-gray-500">{definition.description}</span>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </div>
    );
}
