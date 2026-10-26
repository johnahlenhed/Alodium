// The font options a site can choose (body and headings separately), and the
// <head> markup for the ones selected. The files are self-hosted in
// public/fonts/ (no third-party requests): the latin subset of each variable
// font, downloaded once from Fontsource (@fontsource-variable/inter 5.3.0 and
// @fontsource-variable/source-serif-4 5.3.1), with their OFL licence texts.
// Only the files of the selected options are preloaded and get an @font-face
// rule, so a page never downloads a font it doesn't use.
//
// Only the upright files are included: italic text (em, blockquotes) is slanted
// by the browser. The variable weight axis covers the 400 and 600 the site uses.

/** Latin subset, the same range Fontsource uses, so other scripts fall back. */
const LATIN =
    'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';

const SYSTEM_SANS = "system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

/**
 * Each option: the stack used for --font-body / --font-heading, and for web
 * fonts the file and a metric-matched fallback face. The fallback face is a
 * local font scaled to the web font's average character width, with the web
 * font's line metrics, so text keeps roughly its size and line breaks while
 * the file loads (font-display: swap). The values come from
 * scripts/derive-font-fallbacks.mjs, which reads the shipped woff2 files; rerun
 * it if a file is replaced.
 */
export const FONTS = {
    inter: {
        label: 'Inter',
        family: 'Inter',
        file: '/fonts/inter-latin-wght-normal.woff2',
        weight: '100 900',
        stack: `'Inter', 'Inter Fallback', ${SYSTEM_SANS}`,
        fallback: { family: 'Inter Fallback', local: 'Arial', sizeAdjust: '107.12%', ascent: '90.44%', descent: '22.52%', lineGap: '0%' },
    },
    serif: {
        label: 'Source Serif 4',
        family: 'Source Serif 4',
        file: '/fonts/source-serif-4-latin-wght-normal.woff2',
        weight: '200 900',
        stack: "'Source Serif 4', 'Source Serif 4 Fallback', Georgia, 'Times New Roman', serif",
        fallback: { family: 'Source Serif 4 Fallback', local: 'Times New Roman', sizeAdjust: '117.91%', ascent: '87.87%', descent: '28.41%', lineGap: '0%' },
    },
    system: {
        label: 'System font',
        family: null,
        file: null,
        stack: SYSTEM_SANS,
        fallback: null,
    },
};

/** @font-face rules for the given FONTS entries (web font, then its fallback face). */
export function fontFaceCss(fonts) {
    return fonts
        .filter((font) => font.file)
        .map(({ family, file, weight, fallback }) =>
            [
                `@font-face{font-family:'${family}';font-style:normal;font-weight:${weight};font-display:swap;` +
                    `src:url('${file}') format('woff2');unicode-range:${LATIN}}`,
                `@font-face{font-family:'${fallback.family}';src:local('${fallback.local}');size-adjust:${fallback.sizeAdjust};` +
                    `ascent-override:${fallback.ascent};descent-override:${fallback.descent};line-gap-override:${fallback.lineGap}}`,
            ].join('\n'),
        )
        .join('\n');
}

/** <link rel="preload"> tags for the given FONTS entries (crossorigin is required for fonts). */
export function fontPreloadMarkup(fonts) {
    return fonts
        .filter((font) => font.file)
        .map((font) => `<link rel="preload" href="${font.file}" as="font" type="font/woff2" crossorigin>`)
        .join('\n');
}
