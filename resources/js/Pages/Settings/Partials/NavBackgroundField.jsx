import BackgroundColorField from '@/Components/BackgroundColorField';
import { brandStrong, formatRatio, HEX_PATTERN, NAV_COLOURS, navScheme, worstNavBackgroundChecks } from '@/utils/contrast';

const describe = (check) => `${check.what} at ${formatRatio(check.ratio)}:1 (minimum ${check.minimum.toFixed(1)}:1)`;

/**
 * The site header's background colour (site_settings.nav_background_color),
 * checked as src/lib/navBackground.js does, with the brand colour chosen above
 * (it may change in the same save). The sample is a small header.
 */
export default function NavBackgroundField({ value, onChange, error, brandColor, siteName }) {
    // While the brand field holds an invalid value, the server checks with the default; so does this.
    const brand = HEX_PATTERN.test(brandColor) ? brandColor : null;

    const evaluate = (colour) => {
        if (!brand) return { ok: false, message: 'Enter a valid brand colour above first: the header is checked with it.' };
        const scheme = navScheme(colour, brand);
        if (scheme === 'light') return { ok: true, message: 'OK: the header uses dark text on it, with the current page in a darker shade of the brand colour.' };
        if (scheme === 'dark') return { ok: true, message: 'OK: the header uses white text on it.' };
        const worst = worstNavBackgroundChecks(colour, brand);
        return { ok: false, message: `Neither dark nor white text works on it. With dark text, ${describe(worst.light)}; with white text, ${describe(worst.dark)}. Try a lighter or a darker shade.` };
    };

    const sample = (colour) => {
        const scheme = brand ? navScheme(colour, brand) : null;
        const text = scheme === 'dark' ? NAV_COLOURS.onDark : NAV_COLOURS.text;
        const accent = scheme === 'dark' ? NAV_COLOURS.onDark : brand ? brandStrong(brand) : text;
        return (
            // A small header: the site name, a menu link and the current page (bold, underlined).
            <div aria-hidden="true" className="flex items-center gap-4 rounded-md border border-gray-200 px-3 py-2 text-sm" style={{ backgroundColor: colour, color: text }}>
                <span className="font-semibold">{siteName || 'Site name'}</span>
                <span>Blog</span>
                <span className="font-semibold underline decoration-2 underline-offset-4" style={{ color: accent }}>
                    About
                </span>
            </div>
        );
    };

    return (
        <BackgroundColorField
            id="nav_background_color"
            legend="Header background colour"
            noneLabel="None (the page colour, as now)"
            customLabel="A colour of its own"
            help="Behind the site name, logo and menu. The text turns white on a dark colour. An uploaded logo is shown as it is, so check that it stands out on the colour."
            value={value}
            onChange={onChange}
            error={error}
            suggestion={brand ?? NAV_COLOURS.text}
            evaluate={evaluate}
            sample={sample}
        />
    );
}
