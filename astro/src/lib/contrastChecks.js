// Running a list of contrast checks on a background colour, shared by the page
// background rule (pageBackground.js) and the header background rule
// (navBackground.js). App\Support\ContrastChecks is the PHP copy.

import { contrast, parseHex } from './color.js';

/** [what, rgb, minimum] pairs on a #rrggbb background -> [{ what, ratio, minimum, ok }], in order. */
export function checkPairs(pairs, background) {
    const bg = parseHex(background);
    return pairs.map(([what, colour, minimum]) => {
        const ratio = contrast(colour, bg);
        return { what, ratio, minimum, ok: ratio >= minimum };
    });
}

/** The check furthest below its minimum (the lowest ratio / minimum; the first of equals), or null when all pass. */
export function worstCheck(checks) {
    let worst = null;
    for (const check of checks) {
        if (!check.ok && (worst === null || check.ratio / check.minimum < worst.ratio / worst.minimum)) worst = check;
    }
    return worst;
}
