// The block types the public site can render. To add a type: one entry here and
// one component in this folder. `check(data)` returns null when the block can be
// rendered, otherwise the reason it is skipped (logged as a build warning).
import { hasColumnContent, isText, safeImage, safeLink } from '../../lib/blocks.js';
import ButtonBlock from './ButtonBlock.astro';
import ColumnsBlock from './ColumnsBlock.astro';
import ContactFormBlock from './ContactFormBlock.astro';
import HeroBlock from './HeroBlock.astro';
import ImageBlock from './ImageBlock.astro';
import TextBlock from './TextBlock.astro';

export const BLOCK_TYPES = {
    hero: {
        component: HeroBlock,
        check: (data) => (isText(data.heading) ? null : 'heading is missing'),
    },
    text: {
        component: TextBlock,
        check: (data) => (typeof data.html === 'string' ? null : 'html is missing'),
    },
    image: {
        component: ImageBlock,
        check: (data) => (safeImage(data.image) ? null : 'image is missing or not an allowed URL'),
    },
    columns: {
        component: ColumnsBlock,
        check: (data) => {
            if (!Array.isArray(data.columns)) return 'columns are missing';
            return data.columns.some(hasColumnContent) ? null : 'both columns are empty';
        },
    },
    button: {
        component: ButtonBlock,
        check: (data) => {
            if (!isText(data.label)) return 'label is missing';
            return safeLink(data.url) ? null : 'url is missing or not allowed';
        },
    },
    contact_form: {
        component: ContactFormBlock,
        check: () => null, // heading and intro are optional
    },
};

/**
 * Whether a block can be rendered: its type has a component and its required data
 * is present. Returns { ok: true } or { ok: false, reason }. Used by BlockRenderer
 * (which warns and skips) and by the page template (to find the first rendered block).
 */
export function checkBlock(block) {
    const type = block?.type;
    if (!Object.hasOwn(BLOCK_TYPES, type)) {
        return { ok: false, reason: `type "${type}" has no component yet` };
    }

    const data = block && typeof block.data === 'object' && block.data !== null ? block.data : {};
    const problem = BLOCK_TYPES[type].check(data);

    return problem ? { ok: false, reason: `${type} block: ${problem}` } : { ok: true };
}

/** The first block that will actually be rendered, or undefined. */
export const firstRenderedBlock = (blocks) => (Array.isArray(blocks) ? blocks.find((block) => checkBlock(block).ok) : undefined);
