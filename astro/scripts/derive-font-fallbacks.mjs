// Derives the size-adjust / ascent / descent / line-gap values of the fallback
// faces in src/lib/fonts.js from the shipped woff2 files, and checks that
// fonts.js has them. Rerun it when a font file is replaced.
//
//   node scripts/derive-font-fallbacks.mjs        (from astro/)
//
// Method (the one Next.js and Fontaine use): scale the local font so its
// average character width matches the web font's, then express the web font's
// ascent, descent and line gap relative to that scaled size. The average
// width is weighted by how often each letter occurs in English text.
// The web font metrics are read from the files with @capsizecss/unpack, which
// is installed with Astro (not a dependency of this project, so this script
// may need `npm i --no-save --legacy-peer-deps @capsizecss/unpack` if Astro
// stops shipping it). The local fonts aren't on every machine, so their
// metrics are the published ones from @capsizecss/metrics 4.4.0.

import { fromFile } from '@capsizecss/unpack/fs';
import { fileURLToPath } from 'node:url';
import { FONTS } from '../src/lib/fonts.js';

const LOCAL_METRICS = {
    Arial: { unitsPerEm: 2048, xWidthAvg: 913 },
    'Times New Roman': { unitsPerEm: 2048, xWidthAvg: 832 },
};

// Two decimals, without trailing zeros: 107.12%, 0%.
const percent = (value) => `${Number((value * 100).toFixed(2))}%`;
let mismatches = 0;

for (const [key, font] of Object.entries(FONTS)) {
    if (!font.file) continue;

    const metrics = await fromFile(fileURLToPath(new URL(`../public${font.file}`, import.meta.url)));
    const local = LOCAL_METRICS[font.fallback.local];
    const sizeAdjust = metrics.subsets.latin.xWidthAvg / metrics.unitsPerEm / (local.xWidthAvg / local.unitsPerEm);
    const em = metrics.unitsPerEm * sizeAdjust;

    const derived = {
        sizeAdjust: percent(sizeAdjust),
        ascent: percent(metrics.ascent / em),
        descent: percent(Math.abs(metrics.descent) / em),
        lineGap: percent(metrics.lineGap / em),
    };

    console.log(`${key} (${font.family} over ${font.fallback.local}):`, derived);
    for (const [name, value] of Object.entries(derived)) {
        if (font.fallback[name] !== value) {
            console.error(`  fonts.js has ${name}: ${font.fallback[name]}, expected ${value}`);
            mismatches++;
        }
    }
}

if (mismatches) process.exit(1);
console.log('fonts.js matches.');
