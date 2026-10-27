// Run with: node resources/js/utils/importRun.check.mjs
// Plain Node checks for the import run page's state and wording (no test framework).
import assert from 'node:assert/strict';
import {
    POLL_MS, SLOW_POLL_MS, STALL_MS, REASONS, connectionLost, formatMegabytes, groupReasons,
    initialPollState, isActive, isStalled, milestone, moreFailures, pollDelay, pollFailed,
    pollSucceeded, progress, reasonCode, reasonLabel, showImagePanel, statusLabel, trackStall,
    truncateMiddle, uploadProblem,
} from './importRun.js';

const status = (overrides = {}) => ({
    id: 1, status: 'downloading', download_images: true, items_total: 12, items_done: 3,
    images_found: 71, images_downloaded: 20, images_reused: 10, images_skipped: 7,
    bytes_downloaded: 0, skipped_by_reason: {}, failures: [], failures_recorded: 0, ...overrides,
});
let checks = 0;
const check = (name, fn) => { fn(); checks++; console.log('ok -', name); };

check('active while importing or downloading, not when done or failed', () => {
    assert.equal(isActive(status({ status: 'importing' })), true);
    assert.equal(isActive(status()), true);
    assert.equal(isActive(status({ status: 'done' })), false);
    assert.equal(isActive(status({ status: 'failed' })), false);
    assert.equal(isActive(null), false);
    assert.equal(statusLabel('downloading'), 'Downloading images');
    assert.equal(statusLabel('other'), 'Unknown');
});

check('the image panel shows when images were found or the option was on', () => {
    assert.equal(showImagePanel(status({ download_images: false, images_found: 0 })), false);
    assert.equal(showImagePanel(status({ download_images: true, images_found: 0 })), true);
    assert.equal(showImagePanel(status({ download_images: false, images_found: 3 })), true);
});

check('progress counts downloaded, reused and skipped, never over the total', () => {
    assert.deepEqual(progress(status()), { handled: 37, total: 71, percent: 52, label: '37 of 71 images' });
    assert.equal(progress(status({ images_found: 1, images_downloaded: 0, images_reused: 0, images_skipped: 0 })).label, '0 of 1 image');
    // A download slot is taken before the download finishes, so the sum can run ahead.
    assert.equal(progress(status({ images_found: 2, images_downloaded: 2, images_skipped: 1, images_reused: 0 })).handled, 2);
    assert.equal(progress(status({ status: 'done', images_found: 0, images_downloaded: 0, images_reused: 0, images_skipped: 0 })).percent, 100);
    assert.equal(progress(status({ images_found: 0, images_downloaded: 0, images_reused: 0, images_skipped: 0 })).percent, 0);
});

check('polls every 3 s, and every 10 s after three failures in a row', () => {
    assert.equal(pollDelay(0), POLL_MS);
    assert.equal(pollDelay(2), POLL_MS);
    assert.equal(pollDelay(3), SLOW_POLL_MS);
    assert.equal(POLL_MS, 3000);
    assert.equal(SLOW_POLL_MS, 10000);
    assert.equal(connectionLost(2), false);
    assert.equal(connectionLost(3), true);

    let state = initialPollState(status(), 0);
    state = pollFailed(pollFailed(pollFailed(state)));
    assert.equal(state.failedPolls, 3);
    assert.equal(pollDelay(state.failedPolls), SLOW_POLL_MS);
    // One success resets it.
    state = pollSucceeded(state, status(), 1000);
    assert.equal(state.failedPolls, 0);
});

check('the stall hint needs 60 s of unchanged counters while the run is going', () => {
    let state = initialPollState(status(), 0);
    state = pollSucceeded(state, status(), STALL_MS - 1);
    assert.equal(state.stalled, false);
    state = pollSucceeded(state, status(), STALL_MS);
    assert.equal(state.stalled, true);
    // Any change restarts the clock.
    state = pollSucceeded(state, status({ images_skipped: 8 }), STALL_MS + 3000);
    assert.equal(state.stalled, false);
    state = pollSucceeded(state, status({ images_skipped: 8 }), 2 * STALL_MS + 2999);
    assert.equal(state.stalled, false);
    state = pollSucceeded(state, status({ images_skipped: 8 }), 2 * STALL_MS + 3000);
    assert.equal(state.stalled, true);
    // Never for a finished run, however long it sat still.
    const stall = trackStall(null, status({ status: 'done' }), 0);
    assert.equal(isStalled(stall, status({ status: 'done' }), 10 * STALL_MS), false);
});

check('a failed poll never shows the stall hint by itself', () => {
    let state = initialPollState(status(), 0);
    state = pollFailed(state);
    assert.equal(state.stalled, false);
});

check('only milestones are announced', () => {
    assert.equal(milestone(null, status()), 'Image download started.');
    assert.equal(milestone(null, status({ status: 'done' })), null);
    assert.equal(milestone(status(), status({ images_downloaded: 21 })), null);
    assert.equal(milestone(status({ status: 'importing' }), status()), 'Image download started.');
    assert.equal(milestone(status(), status({ status: 'done' })), 'Image download finished: 20 downloaded, 10 reused, 7 skipped.');
    assert.equal(milestone(status(), status({ status: 'failed' })), 'The import failed.');

    let state = initialPollState(status(), 0);
    assert.equal(state.announcement, 'Image download started.');
    state = pollSucceeded(state, status({ images_downloaded: 25 }), 3000);
    assert.equal(state.announcement, 'Image download started.'); // unchanged text: nothing new is read out
    state = pollSucceeded(state, status({ status: 'done' }), 6000);
    assert.match(state.announcement, /^Image download finished/);
    assert.equal(initialPollState(status({ status: 'done' }), 0).announcement, '');
});

check('reasons map to plain language', () => {
    const cases = {
        'HTTP 404': 'not_found', 'HTTP 410': 'not_found', timeout: 'timeout', 'too large': 'too_large',
        'too many pixels': 'too_large', 'not an image': 'not_image', 'blocked address': 'blocked',
        'host not from the exported site': 'not_allowed_host', 'run limit: 1500 images downloaded': 'run_limit',
        'run limit: 1 GiB downloaded': 'run_limit', 'item limit: more than 50 images': 'item_limit',
        'low disk space': 'low_disk', 'HTTP 500': 'unknown', 'connection failed': 'unknown', '': 'unknown',
    };
    for (const [reason, code] of Object.entries(cases)) assert.equal(reasonCode(reason), code, reason);
    assert.equal(reasonLabel('blocked address'), 'Address not allowed (private or internal)');
    assert.equal(reasonLabel('HTTP 404'), 'Not found (404)');
    assert.equal(reasonLabel('connection failed'), 'Unknown (connection failed)');
    assert.equal(reasonLabel(null), REASONS.unknown);
});

check('skipped images are grouped by reason, largest first', () => {
    assert.deepEqual(groupReasons({ 'HTTP 404': 3, 'HTTP 410': 1, timeout: 2, 'HTTP 500': 1, 'connection failed': 1 }), [
        { code: 'not_found', label: 'Not found (404)', count: 4 },
        { code: 'timeout', label: 'Timed out', count: 2 },
        { code: 'unknown', label: 'Unknown', count: 2 },
    ]);
    assert.deepEqual(groupReasons({}), []);
    assert.deepEqual(groupReasons([]), []); // PHP sends an empty object as [] in some cases
    assert.deepEqual(groupReasons(null), []);
});

check('"and N more" counts the recorded failures not in the list', () => {
    assert.equal(moreFailures(status({ failures: new Array(50).fill({}), failures_recorded: 200 })), 150);
    assert.equal(moreFailures(status({ failures: [{}], failures_recorded: 1 })), 0);
});

check('formatting', () => {
    assert.equal(formatMegabytes(10 * 1024 * 1024), '10 MB');
    assert.equal(formatMegabytes(6515951), '6.2 MB');
    assert.equal(formatMegabytes(0), '0 MB');
    assert.equal(truncateMiddle('short'), 'short');
    const long = `https://old.example/${'a'.repeat(100)}/photo.jpg`;
    const cut = truncateMiddle(long, 40);
    assert.equal(cut.length, 40);
    assert.ok(cut.startsWith('https://old.example/'));
    assert.ok(cut.endsWith('photo.jpg'));
    assert.ok(cut.includes('…'));
});

check('the upload size check', () => {
    const max = 20 * 1024 * 1024;
    assert.equal(uploadProblem(null, max), null);
    assert.equal(uploadProblem({ size: max }, max), null);
    assert.match(uploadProblem({ size: max + 1 }, max), /^This file is 20\.0 MB, and the limit is 20 MB\./);
});

console.log(`\n${checks} checks passed`);
