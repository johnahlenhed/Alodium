import { BlockImageField, Field } from './fields';

export default function ImageBlockEditor({ blockId, data, errors, onChange }) {
    const set = (key) => (value) => onChange({ [key]: value });

    return (
        <div className="space-y-4">
            <BlockImageField label="Image" value={data.image} onChange={set('image')} error={errors['data.image']} />
            <Field
                id={`${blockId}-alt`}
                label="Alt text"
                value={data.alt}
                onChange={set('alt')}
                error={errors['data.alt']}
                maxLength={200}
                hint="Describe the image for people who can't see it, or leave it empty if it's purely decorative."
            />
            <Field
                id={`${blockId}-caption`}
                label="Caption"
                value={data.caption}
                onChange={set('caption')}
                error={errors['data.caption']}
                maxLength={300}
            />
        </div>
    );
}
