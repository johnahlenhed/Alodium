// Generates tests/Fixtures/footer-backgrounds.json from the public site's own rule
// (astro/src/lib/footerBackground.js), so FooterBackgroundTest can check that the PHP
// rule (App\Support\FooterBackground) picks the same scheme for exactly the same
// footer colours, with the same ratios.
//
//   node tests/Fixtures/generate-footer-backgrounds.mjs     (from the repo root)
//
// Run once and commit the JSON; rerun only if the rule changes. Contents, for
// several brand colours (dark, mid, and just above the 4.5:1 brand limit): the
// full grey ramp (dark text, then neither, then white text), the brand colours
// themselves, common footer colours, and a fixed-seed sample of any colour.

import { writeFileSync } from 'node:fs';
import { toHex } from '../../astro/src/lib/color.js';
import { footerBackgroundChecks, footerScheme, FOOTER_SCHEMES, worstFooterBackgroundChecks } from '../../astro/src/lib/footerBackground.js';
import { isValidBrand } from '../../astro/src/lib/theme.js';

const brands = ['#1B2A4A', '#7A1F3D', '#0B5D3B', '#767676', '#0057B8', '#C2185B'];
if (!brands.every(isValidBrand)) throw new Error('Every brand in the fixture must be one the site accepts.');

const backgrounds = new Set(['#FFFFFF', '#000000', '#F5F1EA', '#C9D6EA', '#4A6FA5', '#888888', '#E0E0E0', '#9E9E9E', '#F5F6F8', ...brands]);
for (let v = 0; v <= 255; v += 3) backgrounds.add(toHex([v, v, v]).toUpperCase());
let seed = 11;
const random = () => ((seed = (seed * 1103515245 + 12345) % 2 ** 31) / 2 ** 31);
while (backgrounds.size < 200) backgrounds.add(toHex([0, 0, 0].map(() => Math.floor(random() * 256))).toUpperCase());

const cases = [];
for (const brand of brands) {
    for (const background of backgrounds) {
        const worst = worstFooterBackgroundChecks(background, brand);
        cases.push({
            brand,
            background,
            scheme: footerScheme(background, brand),
            worst: Object.fromEntries(FOOTER_SCHEMES.map((scheme) => [scheme, worst[scheme]?.what ?? null])),
            ratios: Object.fromEntries(FOOTER_SCHEMES.map((scheme) => [scheme, footerBackgroundChecks(background, brand, scheme).map((check) => check.ratio)])),
        });
    }
}

writeFileSync(new URL('./footer-backgrounds.json', import.meta.url), `${JSON.stringify({ cases }, null, 1)}\n`);
const count = (scheme) => cases.filter((c) => c.scheme === scheme).length;
console.log(`${cases.length} cases (${count('light')} dark text, ${count('dark')} white text, ${count(null)} refused) written to tests/Fixtures/footer-backgrounds.json`);
