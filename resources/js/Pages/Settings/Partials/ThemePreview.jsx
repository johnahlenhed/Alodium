import { HEX_PATTERN } from '@/utils/contrast';

/**
 * A small sample of the public site's look: a card with a heading, text and a
 * primary and secondary button in the chosen button style. The colour and
 * corners are passed as CSS variables through the style prop; the derived
 * colours use the same color-mix() formulas as astro/src/styles/blocks.css.
 * It uses the admin's font: the font files aren't loaded here. The card is
 * always light (data-theme="light"), like the public site, also when the admin
 * is in dark mode; its caption follows the admin's theme.
 */
export default function ThemePreview({ brand, radius, buttons, fontLabels }) {
    // Only a valid hex colour is ever put into a CSS value.
    const color = HEX_PATTERN.test(brand) ? brand : '#1B2A4A';
    const style = {
        '--preview-brand': color,
        '--preview-brand-strong': `color-mix(in srgb, ${color} 75%, #000)`,
        '--preview-brand-tint': `color-mix(in srgb, ${color} 7%, #fff)`,
        '--preview-radius': radius,
    };

    const base = 'inline-flex items-center rounded-[var(--preview-radius)] border-2 px-4 py-2 text-sm font-semibold';
    const primary =
        buttons === 'outline'
            ? `${base} border-[var(--preview-brand)] bg-white text-[var(--preview-brand)]`
            : `${base} border-[var(--preview-brand)] bg-[var(--preview-brand)] text-white`;
    const secondary =
        buttons === 'outline'
            ? `${base} border-[var(--preview-brand-tint)] bg-[var(--preview-brand-tint)] text-[var(--preview-brand-strong)]`
            : `${base} border-[var(--preview-brand)] bg-white text-[var(--preview-brand)]`;

    return (
        <figure style={style} aria-labelledby="theme_preview_caption">
            <div data-theme="light" className="rounded-[calc(var(--preview-radius)*1.5)] border border-gray-200 bg-white p-6">
                <p className="text-lg font-semibold text-gray-900">A heading on your site</p>
                <p className="mt-2 text-sm text-gray-600">
                    Body text looks like this, with{' '}
                    <span className="text-[var(--preview-brand)] underline underline-offset-2">a link</span> in the brand
                    colour.
                </p>
                {/* Samples, not controls: spans, so they aren't announced or focusable as buttons. */}
                <div className="mt-4 flex flex-wrap gap-3" aria-hidden="true">
                    <span className={primary}>Primary button</span>
                    <span className={secondary}>Secondary button</span>
                </div>
            </div>
            <figcaption id="theme_preview_caption" className="mt-2 text-sm text-gray-500">
                Preview of the colour, corners and buttons. Fonts: {fontLabels}. The preview uses the admin&rsquo;s font,
                not the exact font of the public site.
            </figcaption>
        </figure>
    );
}
