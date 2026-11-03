import BackgroundColorField from '@/Components/BackgroundColorField';
import { brandStrong, FOOTER_COLOURS, footerScheme, formatRatio, HEX_PATTERN, worstFooterBackgroundChecks } from '@/utils/contrast';

const describe = (check) => `${check.what} at ${formatRatio(check.ratio)}:1 (minimum ${check.minimum.toFixed(1)}:1)`;

/**
 * The site footer's background colour (site_settings.footer_background_color),
 * checked as src/lib/footerBackground.js does, with the brand colour chosen in
 * this form, as NavBackgroundField does for the header. The sample is a small footer.
 */
export default function FooterBackgroundField({ value, onChange, error, brandColor, siteName }) {
    const brand = HEX_PATTERN.test(brandColor) ? brandColor : null;

    const evaluate = (colour) => {
        if (!brand) return { ok: false, message: 'Enter a valid brand colour above first: the footer is checked with it.' };
        const scheme = footerScheme(colour, brand);
        if (scheme === 'light') return { ok: true, message: 'OK: the footer uses dark text on it, with the current page in a darker shade of the brand colour.' };
        if (scheme === 'dark') return { ok: true, message: 'OK: the footer uses white text on it.' };
        const worst = worstFooterBackgroundChecks(colour, brand);
        return { ok: false, message: `Neither dark nor white text works on it. With dark text, ${describe(worst.light)}; with white text, ${describe(worst.dark)}. Try a lighter or a darker shade.` };
    };

    const sample = (colour) => {
        const scheme = brand ? footerScheme(colour, brand) : null;
        const dark = scheme === 'dark';
        const text = dark ? FOOTER_COLOURS.onDark : FOOTER_COLOURS.text;
        return (
            // A small footer: the name, a link, the current page, and a muted line.
            <div aria-hidden="true" className="space-y-1 rounded-md border border-gray-200 px-3 py-2 text-sm" style={{ backgroundColor: colour, color: text }}>
                <div className="flex items-center gap-4">
                    <span className="font-semibold">© {siteName || 'Site name'}</span>
                    <span>Blog</span>
                    <span className="font-semibold underline decoration-2 underline-offset-4" style={{ color: dark ? text : brand ? brandStrong(brand) : text }}>
                        About
                    </span>
                </div>
                <div style={{ color: dark ? text : FOOTER_COLOURS.textMuted }}>This site uses cookie-free analytics.</div>
            </div>
        );
    };

    return (
        <BackgroundColorField
            id="footer_background_color"
            legend="Footer background colour"
            noneLabel="None (the light grey, as now)"
            customLabel="A colour of its own"
            help="Behind the site name, menu links, footer text and analytics note at the bottom of every page. The text turns white on a dark colour."
            value={value}
            onChange={onChange}
            error={error}
            suggestion={brand ?? FOOTER_COLOURS.text}
            evaluate={evaluate}
            sample={sample}
        />
    );
}
