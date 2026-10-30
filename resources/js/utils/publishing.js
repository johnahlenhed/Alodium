// Helpers for the draft / scheduled / published status of posts and pages.
//
// "scheduled" is not stored in the database: it is a draft with a publish_at date.
// publish_at is stored in UTC, while <input type="datetime-local"> works in the
// browser's local time and has no timezone. These helpers convert between the two,
// so 14:30 picked in Sweden is stored as 12:30/13:30 UTC (depending on DST).

const pad = (n) => String(n).padStart(2, '0');

/** UTC ISO string from the server → "YYYY-MM-DDTHH:mm" in local time for the input. */
export function toLocalInput(value) {
    if (!value) return '';
    const d = new Date(value);
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** "YYYY-MM-DDTHH:mm" in local time from the input → UTC ISO string for the server. */
export function toUtcIso(localValue) {
    return localValue ? new Date(localValue).toISOString() : null;
}

/** Status as shown in the admin: draft, scheduled or published. */
export function displayStatus(item) {
    return item.status === 'draft' && item.publish_at ? 'scheduled' : item.status;
}

export const STATUS_BADGE_CLASSES = {
    published: 'bg-success-100 text-success-800',
    scheduled: 'bg-warning-100 text-warning-800',
    draft: 'bg-gray-100 text-gray-700',
};

/** "2026-10-20 14:30" in local time. */
export function formatDateTime(value) {
    return new Date(value).toLocaleString('sv-SE', { dateStyle: 'short', timeStyle: 'short' });
}

/** The browser's timezone name, e.g. "Europe/Stockholm", shown next to the input. */
export const localTimeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
