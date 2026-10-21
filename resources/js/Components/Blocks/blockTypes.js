// Client registry of page block types. To add a type in stage 4: one entry here
// and one editor component. Must match the server types (App\Blocks\Types).
import { Image, LayoutTemplate, Type } from 'lucide-react';
import HeroBlockEditor from './HeroBlockEditor';
import ImageBlockEditor from './ImageBlockEditor';
import TextBlockEditor from './TextBlockEditor';

const stripHtml = (html) => (html ?? '').replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
const fileName = (path) => (path ? decodeURIComponent(path.split('/').pop()) : '');

export const BLOCK_TYPES = {
    hero: {
        label: 'Hero',
        icon: LayoutTemplate,
        description: 'Large heading with optional text, background image and button. Usually the first block.',
        defaultData: { heading: '', subheading: '', image: '', button_label: '', button_url: '' },
        Editor: HeroBlockEditor,
        // Keys in "data" that the editor shows errors for; other errors appear on the card.
        fields: ['heading', 'subheading', 'image', 'button_label', 'button_url'],
        summary: (data) => data.heading?.trim() || 'No heading yet',
    },
    text: {
        label: 'Text',
        icon: Type,
        description: 'Rich text: paragraphs, headings, lists, links and inline images.',
        defaultData: { html: '' },
        Editor: TextBlockEditor,
        fields: ['html'],
        summary: (data) => stripHtml(data.html) || 'Empty text',
    },
    image: {
        label: 'Image',
        icon: Image,
        description: 'A single image with alt text and an optional caption.',
        defaultData: { image: '', alt: '', caption: '' },
        Editor: ImageBlockEditor,
        fields: ['image', 'alt', 'caption'],
        summary: (data) => data.caption?.trim() || data.alt?.trim() || fileName(data.image) || 'No image yet',
    },
};

export const getBlockType = (type) => (Object.hasOwn(BLOCK_TYPES, type) ? BLOCK_TYPES[type] : null);

/** "Hero" or "Unsupported (faq)". */
export const blockLabel = (block) => getBlockType(block.type)?.label ?? `Unsupported (${block.type})`;

export const blockSummary = (block) => getBlockType(block.type)?.summary(block.data ?? {}) ?? 'Kept as it is';
