import { BlockImageField, Field, VariantField } from './fields';

export default function HeroBlockEditor({ blockId, data, errors, onChange, isFirst }) {
    const set = (key) => (value) => onChange({ [key]: value });
    const noteId = `${blockId}-heading-note`;

    return (
        <div className="space-y-4">
            {/* Which heading the site shows: CmsPage.astro (the first rendered block). */}
            <p id={noteId} className="rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-800">
                {isFirst ? (
                    <>
                        <strong className="font-semibold">This heading replaces the page title on the site.</strong>{' '}
                        The hero is the first block, so its heading is the page's main heading (H1) and the page title
                        isn't shown above it. The page title is still used elsewhere: as the menu link and the browser tab
                        title, unless a menu label or meta title is set.
                    </>
                ) : (
                    <>
                        <strong className="font-semibold">The page title stays the page's main heading.</strong>{' '}
                        Only a hero that is the first block replaces it; this heading is shown as a section heading.
                    </>
                )}
            </p>
            <Field
                id={`${blockId}-heading`}
                label="Heading (required)"
                value={data.heading}
                onChange={set('heading')}
                error={errors['data.heading']}
                maxLength={200}
                aria-describedby={noteId}
                // Not the HTML "required" attribute: that would block saving the whole
                // form from inside a collapsed card. The server reports a missing heading.
                aria-required="true"
            />
            <Field
                id={`${blockId}-subheading`}
                label="Subheading"
                value={data.subheading}
                onChange={set('subheading')}
                error={errors['data.subheading']}
                maxLength={500}
                multiline
            />
            <VariantField
                id={`${blockId}-variant`}
                variants={['default', 'light', 'dark', 'none']}
                defaultLabel="Brand colour (white text)"
                value={data.variant}
                onChange={set('variant')}
                error={errors['data.variant']}
            />
            <BlockImageField
                label="Background image"
                description={
                    data.variant === 'none'
                        ? 'Optional. Shown behind the heading as it is, without a colour over it; the text sits on a white panel so it stays readable.'
                        : 'Optional. Shown behind the heading, under a see-through layer of the background colour so the text stays readable.'
                }
                value={data.image}
                onChange={set('image')}
                error={errors['data.image']}
            />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field
                    id={`${blockId}-button-label`}
                    label="Button label"
                    value={data.button_label}
                    onChange={set('button_label')}
                    error={errors['data.button_label']}
                    maxLength={60}
                />
                <Field
                    id={`${blockId}-button-url`}
                    label="Button URL"
                    value={data.button_url}
                    onChange={set('button_url')}
                    error={errors['data.button_url']}
                    placeholder="https://... or /about"
                    maxLength={2048}
                />
            </div>
            <p className="text-sm text-gray-500">The button only shows when both the label and the URL are filled in.</p>
        </div>
    );
}
