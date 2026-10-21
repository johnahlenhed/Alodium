// Run with: node resources/js/Components/Blocks/blocksReducer.check.mjs
// Plain Node checks for the pure block-list operations (no test framework).
import assert from 'node:assert/strict';
import {
    MAX_BLOCKS, addBlock, canAdd, convertToBlocks, errorsByBlockId, hasContent,
    moveBlock, moveBlockBy, newBlockId, removeBlock, updateBlockData,
} from './blocksReducer.js';

const list = () => [
    { id: 'a', type: 'hero', data: { heading: 'A' } },
    { id: 'b', type: 'text', data: { html: '<p>B</p>' } },
    { id: 'c', type: 'image', data: { image: '/storage/1/c.jpg', alt: '' } },
];
const ids = (blocks) => blocks.map((b) => b.id).join(',');
let checks = 0;
const check = (name, fn) => { fn(); checks++; console.log('ok -', name); };

check('add appends with a copy of the default data and a valid id', () => {
    const defaults = { heading: '' };
    const [next, id] = addBlock(list(), 'hero', defaults);
    assert.equal(next.length, 4);
    assert.equal(next[3].id, id);
    assert.match(id, /^[A-Za-z0-9_-]{1,36}$/);
    next[3].data.heading = 'changed';
    assert.equal(defaults.heading, ''); // default data not shared
});

check('add refuses at 50 blocks', () => {
    const full = Array.from({ length: MAX_BLOCKS }, (_, i) => ({ id: `x${i}`, type: 'text', data: {} }));
    assert.equal(canAdd(full), false);
    const [next, id] = addBlock(full, 'text');
    assert.equal(next, full);
    assert.equal(id, null);
});

check('update changes only that block and keeps other blocks identical', () => {
    const before = list();
    const after = updateBlockData(before, 'b', { html: '<p>New</p>' });
    assert.equal(after[1].data.html, '<p>New</p>');
    assert.equal(after[0], before[0]);
    assert.equal(after[2], before[2]);
    assert.notEqual(after[1], before[1]);
    assert.equal(before[1].data.html, '<p>B</p>'); // input not mutated
});

check('move to index, up and down, clamped at the ends', () => {
    assert.equal(ids(moveBlock(list(), 'a', 2)), 'b,c,a');
    assert.equal(ids(moveBlock(list(), 'c', 0)), 'c,a,b');
    assert.equal(ids(moveBlockBy(list(), 'b', -1)), 'b,a,c');
    assert.equal(ids(moveBlockBy(list(), 'b', 1)), 'a,c,b');
    const same = list();
    assert.equal(moveBlockBy(same, 'a', -1), same); // already first: unchanged
    assert.equal(moveBlockBy(same, 'c', 1), same);  // already last: unchanged
    assert.equal(moveBlock(same, 'missing', 0), same);
});

check('move keeps block objects (so React keys and editors survive)', () => {
    const before = list();
    const after = moveBlock(before, 'a', 2);
    assert.equal(after[2], before[0]);
});

check('remove', () => {
    assert.equal(ids(removeBlock(list(), 'b')), 'a,c');
    assert.equal(ids(removeBlock(list(), 'missing')), 'a,b,c');
});

check('convert legacy blocks: keeps ids and data, fills in missing ones', () => {
    const converted = convertToBlocks([
        { id: 'legacy-1', type: 'text', data: { html: '<p>Old</p>' } },
        { type: 'text' },
    ]);
    assert.equal(converted[0].id, 'legacy-1');
    assert.deepEqual(converted[0].data, { html: '<p>Old</p>' });
    assert.ok(converted[1].id);
    assert.deepEqual(converted[1].data, {});
    assert.deepEqual(convertToBlocks(null), []);
});

check('unknown block types pass through untouched', () => {
    const odd = { id: 'z', type: 'faq', data: { q: 'Why?', nested: { a: 1 } } };
    const after = moveBlockBy(updateBlockData([odd, ...list()], 'a', { heading: 'X' }), 'z', 1);
    assert.equal(after[1], odd);
});

check('hasContent', () => {
    assert.equal(hasContent({ heading: '', image: null }), false);
    assert.equal(hasContent({ html: '<p></p>' }), false);
    assert.equal(hasContent({ html: '<p>x</p>' }), true);
    assert.equal(hasContent({ columns: [{ html: '' }, { html: 'y' }] }), true);
});

check('server errors are grouped by the submitted block ids', () => {
    const errors = { title: 'T', 'blocks.1.data.heading': 'H', 'blocks.1.type': 'Type', 'blocks.0.data.image': 'I', blocks: 'Too many' };
    assert.deepEqual(errorsByBlockId(errors, ['a', 'b']), {
        b: { 'data.heading': 'H', type: 'Type' },
        a: { 'data.image': 'I' },
    });
});

check('newBlockId is unique', () => {
    assert.notEqual(newBlockId(), newBlockId());
});

console.log(`\n${checks} checks passed`);
