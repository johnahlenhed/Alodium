// The public site's own colour maths (astro/src/lib/color.js), so the Settings
// page shows exactly the contrast the server checks (App\Support\ColorContrast
// is tested against the same file) and the public site accepts.
import { contrast, HEX_PATTERN, parseHex } from '../../../astro/src/lib/color.js';

export { HEX_PATTERN };

/** Contrast of a #rrggbb colour against white, or null if it isn't a 6-digit hex colour. */
export function contrastWithWhite(hex) {
    return HEX_PATTERN.test(hex) ? contrast(parseHex(hex), [255, 255, 255]) : null;
}

/** Rounded down to one decimal, like the server's message: 4.47 shows as 4.4, never 4.5. */
export function formatRatio(ratio) {
    return (Math.floor(ratio * 10) / 10).toFixed(1);
}
