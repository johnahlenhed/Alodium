// State and wording for the import run page (Pages/Import/Run.jsx) and the
// upload form. Pure functions only, so they can be checked with plain Node:
//   node resources/js/utils/importRun.check.mjs
// The status objects are the JSON of GET /import/runs/{run}/status.

export const POLL_MS = 3000;
export const SLOW_POLL_MS = 10000;
/** Failed polls in a row before polling slows down and "Connection lost" shows. */
export const FAILURES_BEFORE_BACKOFF = 3;
/** Unchanged counters for this long while the run is going: the worker may not be running. */
export const STALL_MS = 60000;

const STATUS_LABELS = {
    importing: 'Importing',
    downloading: 'Downloading images',
    done: 'Done',
    failed: 'Failed',
};

export const statusLabel = (status) => STATUS_LABELS[status] ?? 'Unknown';

/** Still importing or downloading: keep polling. */
export const isActive = (status) => status?.status === 'importing' || status?.status === 'downloading';

/** The image panel is shown when images were found or the option was on. */
export const showImagePanel = (status) => Boolean(status?.download_images) || (status?.images_found ?? 0) > 0;

export const pollDelay = (failedPolls) => (failedPolls >= FAILURES_BEFORE_BACKOFF ? SLOW_POLL_MS : POLL_MS);

export const connectionLost = (failedPolls) => failedPolls >= FAILURES_BEFORE_BACKOFF;

/** Images handled so far (downloaded, reused or skipped) out of those found. */
export function progress(status) {
    const total = status?.images_found ?? 0;
    const handled = Math.min(total, (status?.images_downloaded ?? 0) + (status?.images_reused ?? 0) + (status?.images_skipped ?? 0));
    const done = !isActive(status);
    const percent = total > 0 ? Math.round((handled / total) * 100) : done ? 100 : 0;

    return { handled, total, percent, label: `${handled} of ${total} ${total === 1 ? 'image' : 'images'}` };
}

// --- polling state ---------------------------------------------------------------

/** What has to change for the run to count as moving. */
const signature = (status) => [
    status?.status, status?.items_done, status?.images_downloaded, status?.images_reused, status?.images_skipped, status?.bytes_downloaded,
].join(',');

/** Remembers since when the counters have been the same. */
export function trackStall(previous, status, now) {
    const current = signature(status);

    return previous && previous.signature === current ? previous : { signature: current, since: now };
}

/** Never before STALL_MS: the clock starts when the page opens and restarts on every change. */
export const isStalled = (stall, status, now) => isActive(status) && stall != null && now - stall.since >= STALL_MS;

/** The text for the aria-live region: only the start, the end and a failure, never every poll. */
export function milestone(previous, next) {
    if (!next || previous?.status === next.status) return null;
    if (next.status === 'downloading') return 'Image download started.';
    // Arriving on a run that is already finished announces nothing: the page says it.
    if (!previous) return null;
    if (next.status === 'done') {
        return `Image download finished: ${next.images_downloaded} downloaded, ${next.images_reused} reused, ${next.images_skipped} skipped.`;
    }
    if (next.status === 'failed') return 'The import failed.';

    return null;
}

export function initialPollState(status, now) {
    return {
        status,
        failedPolls: 0,
        stall: trackStall(null, status, now),
        stalled: false,
        announcement: milestone(null, status) ?? '',
    };
}

export function pollSucceeded(state, next, now) {
    const stall = trackStall(state.stall, next, now);

    return {
        status: next,
        failedPolls: 0,
        stall,
        stalled: isStalled(stall, next, now),
        announcement: milestone(state.status, next) ?? state.announcement,
    };
}

/** A failed poll changes nothing but the count (the stall hint waits for real data). */
export const pollFailed = (state) => ({ ...state, failedPolls: state.failedPolls + 1 });

// --- reasons ---------------------------------------------------------------------

export const REASONS = {
    not_found: 'Not found (404)',
    timeout: 'Timed out',
    too_large: 'Too large (file size or pixels)',
    not_image: 'Not an image',
    blocked: 'Address not allowed (private or internal)',
    not_allowed_host: 'Host not from the exported site',
    run_limit: 'Run limit reached',
    item_limit: 'Too many images in one post or page',
    low_disk: 'Low disk space',
    unknown: 'Unknown',
};

/** The server's reason (App\Services\ImageDownload\ImageDownloadFailed and the image job) as a code. */
export function reasonCode(reason) {
    const r = String(reason ?? '').trim().toLowerCase();
    if (r === 'http 404' || r === 'http 410') return 'not_found';
    if (r === 'timeout') return 'timeout';
    if (r === 'too large' || r === 'too many pixels') return 'too_large';
    if (r === 'not an image') return 'not_image';
    if (r === 'blocked address') return 'blocked';
    if (r === 'host not from the exported site') return 'not_allowed_host';
    if (r.startsWith('run limit')) return 'run_limit';
    if (r.startsWith('item limit')) return 'item_limit';
    if (r === 'low disk space') return 'low_disk';

    return 'unknown';
}

/** Plain language; an unknown reason keeps the server's words in brackets. */
export function reasonLabel(reason) {
    const code = reasonCode(reason);

    return code === 'unknown' && reason ? `${REASONS.unknown} (${reason})` : REASONS[code];
}

/** {raw reason: count} from the status, grouped by code, largest first. */
export function groupReasons(skippedByReason) {
    const groups = {};
    for (const [reason, count] of Object.entries(skippedByReason ?? {})) {
        const code = reasonCode(reason);
        groups[code] = (groups[code] ?? 0) + Number(count);
    }

    return Object.entries(groups)
        .map(([code, count]) => ({ code, label: REASONS[code], count }))
        .sort((a, b) => b.count - a.count || a.label.localeCompare(b.label));
}

/** Failures recorded on the server but not in the list of 50. */
export const moreFailures = (status) => Math.max(0, (status?.failures_recorded ?? 0) - (status?.failures?.length ?? 0));

// --- formatting ------------------------------------------------------------------

const MB = 1024 * 1024;

/** 6515951 → "6.2 MB", 10485760 → "10 MB" (MB as in config/import.php: 1024 × 1024). */
export function formatMegabytes(bytes) {
    const mb = (bytes ?? 0) / MB;
    const text = Number.isInteger(mb) ? String(mb) : mb.toFixed(1);

    return `${text} MB`;
}

/** Long addresses keep their start and end: "https://old.example/wp-cont…/photo.jpg". */
export function truncateMiddle(text, max = 80) {
    const value = String(text ?? '');
    if (value.length <= max) return value;
    const keep = max - 1;
    const head = Math.ceil(keep * 0.6);

    return `${value.slice(0, head)}…${value.slice(value.length - (keep - head))}`;
}

/** The client-side size check on the upload form; null when the file is fine. */
export function uploadProblem(file, maxBytes) {
    if (!file) return null;
    if (file.size > maxBytes) {
        return `This file is ${formatMegabytes(file.size)}, and the limit is ${formatMegabytes(maxBytes)}. In WordPress, export one content type at a time (for example only posts) to make smaller files.`;
    }

    return null;
}
