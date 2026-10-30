import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import RichTextEditor from '@/Components/RichTextEditor';
import { BlockImageField, Field, VariantField } from './fields';

const ALT_HINT = "Describe the image for people who can't see it, or leave it empty if it's purely decorative.";

/** Two side-by-side columns, each with text, an optional image and its alt text. */
export default function ColumnsBlockEditor({ blockId, data, errors, onChange }) {
    const columns = data.columns ?? [];

    // Updates one column from the latest data (not a possibly stale copy).
    const setColumn = (index, patch) =>
        onChange((current) => ({
            columns: (current.columns ?? []).map((column, i) => (i === index ? { ...column, ...patch } : column)),
        }));

    return (
        <div className="space-y-4">
            <InputError message={errors['data.columns']} />
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {columns.map((column, index) => (
                    <fieldset key={index} className="space-y-4 rounded-md border border-gray-200 p-3">
                        <legend className="px-1 text-sm font-medium text-gray-700">Column {index + 1}</legend>
                        <div>
                            <InputLabel value={`Column ${index + 1} text`} />
                            <RichTextEditor value={column.html ?? ''} onChange={(html) => setColumn(index, { html })} />
                            <InputError message={errors[`data.columns.${index}.html`]} className="mt-1" />
                        </div>
                        <BlockImageField
                            label={`Column ${index + 1} image`}
                            description="Optional. Shown above the text."
                            value={column.image}
                            onChange={(image) => setColumn(index, { image })}
                            error={errors[`data.columns.${index}.image`]}
                        />
                        <Field
                            id={`${blockId}-column-${index}-alt`}
                            label={`Column ${index + 1} alt text`}
                            value={column.alt}
                            onChange={(alt) => setColumn(index, { alt })}
                            error={errors[`data.columns.${index}.alt`]}
                            maxLength={200}
                            hint={ALT_HINT}
                        />
                    </fieldset>
                ))}
            </div>
            <p className="text-sm text-gray-500">An empty column (no text and no image) is left out on the site.</p>
            <VariantField
                id={`${blockId}-variant`}
                variants={['default', 'light', 'brand', 'dark']}
                value={data.variant}
                onChange={(variant) => onChange({ variant })}
                error={errors['data.variant']}
            />
        </div>
    );
}
