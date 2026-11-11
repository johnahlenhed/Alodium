import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import SecondaryButton from '@/Components/SecondaryButton';
import TextInput from '@/Components/TextInput';
import { buildCampaignUrl } from '@/utils/utm';
import { useState } from 'react';

const FIELDS = [
    ['source', 'Source', 'newsletter', 'Where the link is shared, for example newsletter, facebook or a partner’s site.'],
    ['medium', 'Medium', 'email', 'The kind of link, for example email, social or cpc (a paid ad).'],
    ['campaign', 'Campaign', 'autumn-sale', 'Which campaign it belongs to, for example autumn-sale.'],
];

const hostOf = (address) => {
    try {
        return new URL(address).hostname.replace(/^www\./, '');
    } catch {
        return null;
    }
};

/**
 * Builds a link with utm_source, utm_medium and utm_campaign for the page
 * chosen, to copy into a newsletter, a post or an ad. Nothing is saved or sent
 * to the server: the values live in the link itself, so plain state rather
 * than useForm. The values go in as they will be stored (resources/js/utils/
 * utm.js, the server's rules), and one that would be dropped (an email
 * address, an id) is explained instead of making a link.
 */
export default function CampaignLinkBuilder({ publicSiteUrl }) {
    const [values, setValues] = useState({ address: publicSiteUrl ?? '', source: '', medium: '', campaign: '' });
    // A field's error shows once it has been typed in or left, not on an empty form.
    const [touched, setTouched] = useState({});
    const [status, setStatus] = useState('');

    const { url, errors, notes } = buildCampaignUrl(values.address, values);
    const shown = (field) => (touched[field] ? errors[field] : undefined);
    const set = (field) => (e) => {
        setValues({ ...values, [field]: e.target.value });
        setTouched({ ...touched, [field]: true });
        setStatus('');
    };
    const leave = (field) => () => setTouched({ ...touched, [field]: true });

    const siteHost = hostOf(publicSiteUrl);
    const otherHost = url && siteHost && hostOf(values.address) !== siteHost;

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(url);
            setStatus('Link copied.');
        } catch {
            // No clipboard access (an insecure origin, a denied permission): select it to copy by hand.
            document.getElementById('utm_link')?.select?.();
            setStatus('Couldn’t copy automatically. The link is selected: press Ctrl+C (⌘C on a Mac) to copy it.');
        }
    };

    return (
        <section aria-labelledby="campaign-link-builder" className="bg-surface p-6 shadow sm:rounded-lg">
            <h3 id="campaign-link-builder" className="text-sm font-semibold text-gray-900">
                Campaign link builder
            </h3>
            <p className="mt-1 max-w-3xl text-sm text-gray-600">
                Tag a link to your site for a newsletter, a social post or an ad, and its visits show up under Campaigns,
                Mediums and Traffic sources above. Nothing is saved: the labels travel in the link itself. Use short labels
                about the campaign, never anything about a person.
            </p>

            <div className="mt-4 space-y-4">
                <div>
                    <InputLabel htmlFor="utm_address" value="Page address" />
                    <TextInput
                        id="utm_address"
                        type="url"
                        className="mt-1 block w-full"
                        value={values.address}
                        onChange={set('address')}
                        onBlur={leave('address')}
                        placeholder="https://example.com/offer/"
                        aria-invalid={shown('address') ? true : undefined}
                        aria-describedby={otherHost ? 'utm_address_note' : undefined}
                        spellCheck={false}
                    />
                    {otherHost && (
                        <p id="utm_address_note" className="mt-1 text-sm text-gray-600">
                            Only visits to {siteHost} are counted, where the tracker runs.
                        </p>
                    )}
                    <InputError message={shown('address')} className="mt-1" />
                </div>

                <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                    {FIELDS.map(([field, label, placeholder, hint]) => (
                        <div key={field}>
                            <InputLabel htmlFor={`utm_${field}`} value={`${label} (required)`} />
                            <TextInput
                                id={`utm_${field}`}
                                className="mt-1 block w-full"
                                value={values[field]}
                                onChange={set(field)}
                                onBlur={leave(field)}
                                placeholder={placeholder}
                                maxLength={200}
                                autoComplete="off"
                                spellCheck={false}
                                aria-required="true"
                                aria-invalid={shown(field) ? true : undefined}
                                aria-describedby={`utm_${field}_hint`}
                            />
                            <p id={`utm_${field}_hint`} className="mt-1 text-sm text-gray-500">
                                {notes[field] ?? hint}
                            </p>
                            <InputError message={shown(field)} className="mt-1" />
                        </div>
                    ))}
                </div>

                <div>
                    <InputLabel htmlFor="utm_link" value="Tagged link" />
                    {url ? (
                        <div className="mt-1 flex flex-col gap-2 sm:flex-row">
                            <TextInput id="utm_link" className="block w-full font-mono text-sm" value={url} readOnly onFocus={(e) => e.target.select()} />
                            <SecondaryButton onClick={copy} className="shrink-0 justify-center">
                                Copy link
                            </SecondaryButton>
                        </div>
                    ) : (
                        <p id="utm_link" className="mt-1 text-sm text-gray-500">
                            Fill in the page address, source, medium and campaign to get the link.
                        </p>
                    )}
                    <p aria-live="polite" className="mt-2 text-sm text-gray-700">
                        {status}
                    </p>
                </div>
            </div>
        </section>
    );
}
