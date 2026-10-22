// Pure block-list operations for the page block editor. No React, no imports from
// the app, so they can be checked with plain Node (see blocksReducer.check.mjs).
// Every function returns a new array and never mutates its input; blocks that
// don't change keep their object identity, so memoised cards don't re-render.

/** Same limit as the server (App\Blocks\BlockRegistry::MAX_BLOCKS). */
export const MAX_BLOCKS = 50;

/** A block id: letters, digits, - and _, max 36 characters (server rule). */
export function newBlockId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    // crypto.randomUUID needs a secure context (https or localhost).
    return 'b-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

export const canAdd = (blocks) => blocks.length < MAX_BLOCKS;

/** Appends a block of `type` with a copy of `defaultData`. Returns [blocks, newId]. */
export function addBlock(blocks, type, defaultData = {}, id = newBlockId()) {
    if (!canAdd(blocks)) return [blocks, null];
    return [[...blocks, { id, type, data: structuredClone(defaultData) }], id];
}

/**
 * Merges `patch` into one block's data. Other blocks keep their identity.
 * `patch` can also be a function of the current data (for nested lists such as
 * columns, so an update is based on the latest data, not a stale copy).
 */
export function updateBlockData(blocks, id, patch) {
    return blocks.map((block) => {
        if (block.id !== id) return block;
        const changes = typeof patch === 'function' ? patch(block.data ?? {}) : patch;
        return { ...block, data: { ...block.data, ...changes } };
    });
}

/** Moves a block to `toIndex` (clamped to the list). */
export function moveBlock(blocks, id, toIndex) {
    const from = blocks.findIndex((block) => block.id === id);
    if (from === -1) return blocks;
    const to = Math.max(0, Math.min(blocks.length - 1, toIndex));
    if (from === to) return blocks;

    const next = [...blocks];
    const [moved] = next.splice(from, 1);
    next.splice(to, 0, moved);
    return next;
}

/** Moves a block up (-1) or down (+1). */
export const moveBlockBy = (blocks, id, delta) =>
    moveBlock(blocks, id, blocks.findIndex((block) => block.id === id) + delta);

export const removeBlock = (blocks, id) => blocks.filter((block) => block.id !== id);

/**
 * The block list for a legacy page being converted (from Page::blocksOrLegacy()
 * on the server). Makes sure every block has an id and a data object.
 */
export function convertToBlocks(legacyBlocks) {
    return (Array.isArray(legacyBlocks) ? legacyBlocks : []).map((block) => ({
        id: block.id || newBlockId(),
        type: block.type,
        data: block.data && typeof block.data === 'object' ? block.data : {},
    }));
}

/** True when any value in the data is a non-empty string (used to confirm deletes). */
export function hasContent(data) {
    if (typeof data === 'string') return data.trim() !== '' && data.trim() !== '<p></p>';
    if (Array.isArray(data)) return data.some(hasContent);
    if (data && typeof data === 'object') return Object.values(data).some(hasContent);
    return false;
}

/**
 * Server errors ("blocks.2.data.heading") grouped by block id, using the block
 * order that was submitted, so errors stay on the right block even if blocks
 * are moved before the next save. Each group maps the key after "blocks.N."
 * (e.g. "data.heading", "type") to its message.
 */
export function errorsByBlockId(errors, submittedIds) {
    const grouped = {};
    for (const [key, message] of Object.entries(errors ?? {})) {
        const match = /^blocks\.(\d+)\.(.+)$/.exec(key);
        const id = match && submittedIds[Number(match[1])];
        if (!id) continue;
        (grouped[id] ??= {})[match[2]] = message;
    }
    return grouped;
}
