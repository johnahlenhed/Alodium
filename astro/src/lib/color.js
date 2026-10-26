// Colour maths shared by the theme (src/lib/theme.js) and the contrast check
// (scripts/check-contrast.mjs). Colours are [r, g, b] arrays with 0–255 channels.

export const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;

/** "#1B2A4A" -> [27, 42, 74]. Only call with a value that matches HEX_PATTERN. */
export function parseHex(hex) {
    return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
}

/** [27, 42, 74] -> "#1b2a4a" (channels are rounded). */
export function toHex(rgb) {
    return `#${rgb.map((c) => Math.round(c).toString(16).padStart(2, '0')).join('')}`;
}

/**
 * Same result as CSS color-mix(in srgb, a <weight * 100>%, b): each channel is
 * interpolated in gamma-encoded sRGB. Drawing a translucent colour over another
 * (an overlay) gives the same result, which is how the hero overlay is checked.
 */
export function mix(a, b, weight) {
    return a.map((channel, i) => channel * weight + b[i] * (1 - weight));
}

/** WCAG 2 relative luminance. */
export function luminance(rgb) {
    const [r, g, b] = rgb.map((channel) => {
        const c = channel / 255;
        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG 2 contrast ratio, from 1 to 21. */
export function contrast(a, b) {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (light + 0.05) / (dark + 0.05);
}
