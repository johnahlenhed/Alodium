import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import RichTextEditor from '@/Components/RichTextEditor';

export default function TextBlockEditor({ data, errors, onChange }) {
    return (
        <div>
            <InputLabel value="Text" />
            <RichTextEditor value={data.html} onChange={(html) => onChange({ html })} />
            <InputError message={errors['data.html']} className="mt-1" />
        </div>
    );
}
