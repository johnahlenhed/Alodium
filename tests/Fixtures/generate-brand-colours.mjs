// Generates tests/Fixtures/brand-colours.json from the public site's own code
// (astro/src/lib/color.js and theme.js), so BrandColorTest can check that the
// PHP rule accepts exactly the brand colours the Astro site accepts.
//
//   node tests/Fixtures/generate-brand-colours.mjs     (from the repo root)
//
// Run once and commit the JSON; rerun only if the contrast rule changes.
// Contents: the colours closest to the 4.5:1 limit on both sides (found by
// checking all 16.7 million), well-known colours, and a fixed-seed sample.

import { writeFileSync } from 'node:fs';
import { contrast, parseHex, toHex } from '../../astro/src/lib/color.js';
import { isValidBrand, MIN_BRAND_CONTRAST } from '../../astro/src/lib/theme.js';

const WHITE = [255, 255, 255];
const BORDERLINE_EACH_SIDE = 60;

// 1. The colours nearest the limit. A quick pass with a lookup table finds
// candidates; the stored ratio and verdict come from color.js and theme.js.
const linear = Array.from({ length: 256 }, (_, i) => {
    const c = i / 255;
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
});
const below = [];
const above = [];
const keep = (list, distance, rgb) => {
    if (list.length < BORDERLINE_EACH_SIDE * 4 || distance < list.at(-1)[0]) {
        list.push([distance, rgb]);
        list.sort((a, b) => a[0] - b[0]);
        if (list.length > BORDERLINE_EACH_SIDE * 4) list.pop();
    }
};
for (let r = 0; r < 256; r++) {
    for (let g = 0; g < 256; g++) {
        for (let b = 0; b < 256; b++) {
            const ratio = 1.05 / (0.2126 * linear[r] + 0.7152 * linear[g] + 0.0722 * linear[b] + 0.05);
            const distance = Math.abs(ratio - MIN_BRAND_CONTRAST);
            if (distance > 0.001) continue;
            keep(ratio >= MIN_BRAND_CONTRAST ? above : below, distance, [r, g, b]);
        }
    }
}
const nearest = (list) => list.slice(0, BORDERLINE_EACH_SIDE).map(([, rgb]) => toHex(rgb));

// 2. Well-known colours: the defaults, the colours from the contrast check, extremes.
const known = ['#000000', '#FFFFFF', '#1B2A4A', '#7A1F3D', '#0B5D3B', '#767676', '#777777', '#FFFF00', '#2563EB', '#2A9DF4', '#FF6600', '#808080', '#595959', '#0000FF', '#FF0000', '#008000'];

// 3. A fixed-seed sample (same generator as astro/scripts/check-contrast.mjs).
let seed = 7;
const random = () => ((seed = (seed * 1103515245 + 12345) % 2 ** 31) / 2 ** 31);
const sample = Array.from({ length: 200 }, () => toHex([random() * 256, random() * 256, random() * 256].map(Math.floor)));

// Mixed case on purpose: both are valid input (the server stores uppercase).
const hexes = [...new Set([...nearest(below), ...nearest(above), ...known, ...sample].map((hex) => hex.toUpperCase()))].map((hex, i) =>
    i % 3 === 0 ? hex.toLowerCase() : hex,
);

const colours = hexes.map((hex) => ({
    hex,
    ratio: contrast(parseHex(hex), WHITE),
    accepted: isValidBrand(hex),
}));

const fixture = {
    generated_by: 'tests/Fixtures/generate-brand-colours.mjs from astro/src/lib/color.js and theme.js',
    minimum: MIN_BRAND_CONTRAST,
    count: colours.length,
    accepted: colours.filter((c) => c.accepted).length,
    colours,
};

writeFileSync(new URL('./brand-colours.json', import.meta.url), `${JSON.stringify(fixture, null, 1)}\n`);
const closest = (list) => list[0] && contrast(list[0][1], WHITE);
console.log(`${colours.length} colours (${fixture.accepted} accepted). Closest to the limit: ${closest(below)} below, ${closest(above)} above.`);
