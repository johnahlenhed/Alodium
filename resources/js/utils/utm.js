// UTM values for the campaign link builder (Analytics): the rules the server
// stores them by (App\Support\UtmValue) and the tracker sends them by
// (public/tracker.js), so the builder can say what will be dropped or changed
// before the link is shared. All three agree on tests/Fixtures/utm-values.json
// (resources/js/utils/utm.check.mjs).

export const UTM_FIELDS = Object.freeze(['utm_source', 'utm_medium', 'utm_campaign']);
/** Never read or stored (App\Support\UtmValue): removed from a link the builder tags. */
export const UTM_NOT_COLLECTED = Object.freeze(['utm_term', 'utm_content']);
export const UTM_MAX_LENGTH = 100;

const INVISIBLE = /[\u0000-\u0008\u000E-\u001F\u007F-\u009F\u00AD\u200B-\u200F\u202A-\u202E\u2060-\u2064\uFEFF]/g;
const SPACES = /[\s\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]+/g;

/**
 * What happens to a value: { value, reason, shortened }. `value` is what is
 * stored (null when dropped); `reason` why it is dropped: 'empty', 'email'
 * (an "@"), 'number' (7 or more digits in a row) or 'code' (a long or
 * letters-and-digits word, like a hash or an id), else null.
 */
export function inspectUtm(raw) {
    if (typeof raw !== 'string') return { value: null, reason: 'empty', shortened: false };
    const value = raw.replace(INVISIBLE, '').replace(SPACES, ' ').trim().toLowerCase();
    if (value === '') return { value: null, reason: 'empty', shortened: false };
    if (value.includes('@')) return { value: null, reason: 'email', shortened: false };
    if (/\d{7,}/.test(value)) return { value: null, reason: 'number', shortened: false };
    for (const word of value.split(/[^a-z0-9]+/)) {
        const mixed = /[a-z]/.test(word) && /\d/.test(word);
        if (word.length >= 24 || (mixed && word.length >= 16) || (mixed && word.length >= 8 && /^[0-9a-f]+$/.test(word))) {
            return { value: null, reason: 'code', shortened: false };
        }
    }
    const characters = Array.from(value); // code points, as the server counts
    const shortened = characters.length > UTM_MAX_LENGTH;

    return { value: shortened ? characters.slice(0, UTM_MAX_LENGTH).join('').trimEnd() : value, reason: null, shortened };
}

/** The value as stored, or null: App\Support\UtmValue::clean(). */
export const cleanUtm = (raw) => inspectUtm(raw).value;

/**
 * A tagged link from a page address and { source, medium, campaign }:
 * { url, errors, notes }. `url` is null while anything is missing or would be
 * dropped; `errors` and `notes` are keyed by field ('address', 'source',
 * 'medium', 'campaign'). The values go into the link as they will be stored
 * (lowercase, trimmed), so the link shows what the reports will. Other query
 * parameters and the #fragment are kept; any UTM parameters already in the
 * address are replaced, and utm_term and utm_content removed.
 */
export function buildCampaignUrl(address, fields) {
    const errors = {};
    const notes = {};

    let url = null;
    const trimmed = typeof address === 'string' ? address.trim() : '';
    try {
        url = new URL(trimmed);
        if (url.protocol !== 'https:' && url.protocol !== 'http:') url = null;
    } catch {
        url = null;
    }
    if (!url) errors.address = 'Enter the full address of a page, starting with https://';

    const values = {};
    for (const field of ['source', 'medium', 'campaign']) {
        const { value, reason, shortened } = inspectUtm(fields?.[field]);
        values[field] = value;
        if (reason === 'empty') errors[field] = 'Required.';
        else if (reason === 'email') errors[field] = 'Looks like an email address, so it would not be stored. Use a short label.';
        else if (reason === 'number') errors[field] = 'Has a long number, which could identify a person, so it would not be stored. Use a short label.';
        else if (reason === 'code') errors[field] = 'Looks like a code or an id, so it would not be stored. Use a short, readable label.';
        else if (shortened) notes[field] = `Shortened to ${UTM_MAX_LENGTH} characters.`;
        else if (value !== fields[field].trim()) notes[field] = `Stored as “${value}”.`;
    }

    if (!url || Object.keys(errors).length) return { url: null, errors, notes };

    for (const key of [...UTM_FIELDS, ...UTM_NOT_COLLECTED]) url.searchParams.delete(key);
    url.searchParams.set('utm_source', values.source);
    url.searchParams.set('utm_medium', values.medium);
    url.searchParams.set('utm_campaign', values.campaign);

    return { url: url.toString(), errors, notes };
}
