import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import RichTextEditor from '@/Components/RichTextEditor';
import { VariantField } from './fields';

export default function TextBlockEditor({ blockId, data, errors, onChange }) {
    return (
        <div className="space-y-4">
            <div>
                <InputLabel value="Text" />
                <RichTextEditor value={data.html} onChange={(html) => onChange({ html })} />
                <InputError message={errors['data.html']} className="mt-1" />
            </div>
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
