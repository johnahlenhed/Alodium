// Generates tests/Fixtures/page-backgrounds.json from the public site's own rule
// (astro/src/lib/pageBackground.js), so PageBackgroundTest can check that the
// PHP rule (App\Support\PageBackground) accepts exactly the same page
// background colours, with the same ratios.
//
//   node tests/Fixtures/generate-page-backgrounds.mjs     (from the repo root)
//
// Run once and commit the JSON; rerun only if the rule changes. Contents, for
// several brand colours (dark, mid, and just above the 4.5:1 brand limit): the
// greys and a light pastel ramp around where the rule starts to accept, common
// page colours, and a fixed-seed sample of light colours.

import { writeFileSync } from 'node:fs';
import { toHex } from '../../astro/src/lib/color.js';
import { isValidPageBackground, pageBackgroundChecks, worstPageBackgroundCheck } from '../../astro/src/lib/pageBackground.js';
import { isValidBrand } from '../../astro/src/lib/theme.js';

const brands = ['#1B2A4A', '#7A1F3D', '#0B5D3B', '#767676', '#0057B8', '#C2185B'];
if (!brands.every(isValidBrand)) throw new Error('Every brand in the fixture must be one the site accepts.');

const backgrounds = new Set(['#FFFFFF', '#F5F6F8', '#FAF7F2', '#F0F4FF', '#E8F5E9', '#FFF3C4', '#EEEEEE', '#E0E0E0', '#D0E4FF', '#000000', '#1B2A4A']);
for (let v = 200; v <= 255; v++) backgrounds.add(toHex([v, v, v]).toUpperCase()); // greys across the limit
for (let v = 200; v <= 255; v += 2) backgrounds.add(toHex([255, v, v - 40]).toUpperCase()); // warm pastels

let seed = 7;
const random = () => ((seed = (seed * 1103515245 + 12345) % 2 ** 31) / 2 ** 31);
while (backgrounds.size < 160) backgrounds.add(toHex([0, 0, 0].map(() => 190 + Math.floor(random() * 66))).toUpperCase());

const cases = [];
for (const brand of brands) {
    for (const background of backgrounds) {
        cases.push({
            brand,
            background,
            accepted: isValidPageBackground(background, brand),
            worst: worstPageBackgroundCheck(background, brand)?.what ?? null,
            ratios: pageBackgroundChecks(background, brand).map((check) => check.ratio),
        });
    }
}

writeFileSync(new URL('./page-backgrounds.json', import.meta.url), `${JSON.stringify({ cases }, null, 1)}\n`);
const accepted = cases.filter((c) => c.accepted).length;
console.log(`${cases.length} cases (${accepted} accepted, ${cases.length - accepted} rejected) written to tests/Fixtures/page-backgrounds.json`);
