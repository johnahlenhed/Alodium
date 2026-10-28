import { BlockImageField, Field, VariantField } from './fields';

export default function HeroBlockEditor({ blockId, data, errors, onChange, isFirst }) {
    const set = (key) => (value) => onChange({ [key]: value });

    return (
        <div className="space-y-4">
            <p className="rounded-md bg-indigo-50 px-3 py-2 text-sm text-indigo-800">
                {isFirst
                    ? "This hero is the first block, so its heading is the page's main heading and the page title isn't shown on the site."
                    : "When a hero is the first block, its heading becomes the page's main heading and the page title isn't shown on the site."}
            </p>
            <Field
                id={`${blockId}-heading`}
                label="Heading (required)"
                value={data.heading}
                onChange={set('heading')}
                error={errors['data.heading']}
                maxLength={200}
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
                variants={['default', 'light', 'dark']}
                defaultLabel="Brand colour (white text)"
                value={data.variant}
                onChange={set('variant')}
                error={errors['data.variant']}
            />
            <BlockImageField
                label="Background image"
                description="Optional. Shown behind the heading, covered in the background colour (lightened for Light) so the text stays readable."
                value={data.image}
                onChange={set('image')}
                error={errors['data.image']}
            />
            <div className="grid gap-4 sm:grid-cols-2">
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
