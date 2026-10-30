import { Field } from './fields';

export default function ContactFormBlockEditor({ blockId, data, errors, onChange }) {
    const set = (key) => (value) => onChange({ [key]: value });

    return (
        <div className="space-y-4">
            <p className="rounded-md bg-brand-50 px-3 py-2 text-sm text-brand-800">
                Shows the site's contact form (name, email and message). Messages appear under Submissions in
                the admin menu and are also emailed to the site's contact address.
            </p>
            <Field
                id={`${blockId}-heading`}
                label="Heading"
                value={data.heading}
                onChange={set('heading')}
                error={errors['data.heading']}
                maxLength={200}
                placeholder="Optional, for example: Get in touch"
            />
            <Field
                id={`${blockId}-intro`}
                label="Intro"
                value={data.intro}
                onChange={set('intro')}
                error={errors['data.intro']}
                maxLength={500}
                multiline
            />
        </div>
    );
}
