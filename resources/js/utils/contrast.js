// The public site's own colour maths (astro/src/lib/color.js), so the Settings
// page shows exactly the contrast the server checks (App\Support\ColorContrast
// is tested against the same file) and the public site accepts.
import { contrast, HEX_PATTERN, mix, parseHex, toHex } from '../../../astro/src/lib/color.js';

export { HEX_PATTERN };
// A page's background colour: the rule the server (App\Support\PageBackground) and the build use.
export { PAGE_COLOURS, worstPageBackgroundCheck } from '../../../astro/src/lib/pageBackground.js';
// The header's background colour: the same for App\Support\NavBackground.
export { NAV_COLOURS, navScheme, worstNavBackgroundChecks } from '../../../astro/src/lib/navBackground.js';

/** --color-brand-strong for this brand (#rrggbb), as blocks.css mixes it: links on a page background. */
export function brandStrong(hex) {
    return toHex(mix(parseHex(hex), [0, 0, 0], 0.75));
}

/** Contrast of a #rrggbb colour against white, or null if it isn't a 6-digit hex colour. */
export function contrastWithWhite(hex) {
    return HEX_PATTERN.test(hex) ? contrast(parseHex(hex), [255, 255, 255]) : null;
}

/** Rounded down to one decimal, like the server's message: 4.47 shows as 4.4, never 4.5. */
export function formatRatio(ratio) {
    return (Math.floor(ratio * 10) / 10).toFixed(1);
}
