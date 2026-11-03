import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import RichTextEditor from '@/Components/RichTextEditor';
import { hasContent, MAX_COLUMNS, MIN_COLUMNS, resizeColumns } from './blocksReducer';
import { BlockImageField, Field, inputClass, VariantField } from './fields';

const ALT_HINT = "Describe the image for people who can't see it, or leave it empty if it's purely decorative.";
const COUNTS = Array.from({ length: MAX_COLUMNS - MIN_COLUMNS + 1 }, (_, i) => MIN_COLUMNS + i);

/** Two or three side-by-side columns, each with text, an optional image and its alt text. */
export default function ColumnsBlockEditor({ blockId, data, errors, onChange }) {
    const columns = data.columns ?? [];

    // Updates one column from the latest data (not a possibly stale copy).
    const setColumn = (index, patch) =>
        onChange((current) => ({
            columns: (current.columns ?? []).map((column, i) => (i === index ? { ...column, ...patch } : column)),
        }));

    // Fewer columns cuts the last one (3 to 2), so ask first if it has content.
    const setCount = (count) => {
        if (hasContent(columns.slice(count)) && !window.confirm(`Remove column ${count + 1}? Its content will be lost when you save.`)) {
            return;
        }
        onChange((current) => ({ columns: resizeColumns(current.columns, count) }));
    };

    return (
        <div className="space-y-4">
            <div>
                <InputLabel htmlFor={`${blockId}-count`} value="Number of columns" />
                <select
                    id={`${blockId}-count`}
                    className={inputClass}
                    value={columns.length}
                    onChange={(e) => setCount(Number(e.target.value))}
                >
                    {COUNTS.map((count) => (
                        <option key={count} value={count}>
                            {count} columns of equal width
                        </option>
                    ))}
                </select>
                <InputError message={errors['data.columns']} className="mt-1" />
            </div>
            {/* Three side by side would be too narrow for the text editors in this form, so they stack here.
                Two stack only below lg, so the note is shown only while the columns are stacked. */}
            <p className={`text-sm text-gray-500 ${columns.length === 2 ? 'lg:hidden' : ''}`}>
                The columns are stacked here to make editing easier. On the published page they sit side by side
                (stacked on phones).
            </p>
            <div className={`grid grid-cols-1 gap-4 ${columns.length === 2 ? 'lg:grid-cols-2' : ''}`}>
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
