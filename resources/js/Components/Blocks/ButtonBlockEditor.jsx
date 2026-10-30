import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { Field, inputClass } from './fields';

export default function ButtonBlockEditor({ blockId, data, errors, onChange }) {
    const set = (key) => (value) => onChange({ [key]: value });

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field
                    id={`${blockId}-label`}
                    label="Label (required)"
                    value={data.label}
                    onChange={set('label')}
                    error={errors['data.label']}
                    maxLength={60}
                    aria-required="true"
                />
                <Field
                    id={`${blockId}-url`}
                    label="Link (required)"
                    value={data.url}
                    onChange={set('url')}
                    error={errors['data.url']}
                    placeholder="https://... or /about"
                    maxLength={2048}
                    aria-required="true"
                    hint="Must start with https://, mailto:, tel: or /."
                />
            </div>
            <div>
                <InputLabel htmlFor={`${blockId}-style`} value="Style" />
                <select
                    id={`${blockId}-style`}
                    className={inputClass}
                    value={data.style || 'primary'}
                    onChange={(e) => onChange({ style: e.target.value })}
                >
                    <option value="primary">Primary (filled)</option>
                    <option value="secondary">Secondary (outline)</option>
                </select>
                <InputError message={errors['data.style']} className="mt-1" />
            </div>
        </div>
    );
}
