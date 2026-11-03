import BackgroundColorField from '@/Components/BackgroundColorField';
import { brandStrong, formatRatio, PAGE_COLOURS, worstPageBackgroundCheck } from '@/utils/contrast';

/** Suggested when "A colour of its own" is first chosen: a light warm grey that passes with any brand. */
const SUGGESTION = '#F5F1EA';

/** The page's background colour (pages.background_color), checked as src/lib/pageBackground.js does. */
export default function PageBackgroundField({ value, onChange, error, brandColor }) {
    const evaluate = (colour) => {
        const worst = worstPageBackgroundCheck(colour, brandColor);
        return worst
            ? { ok: false, message: `Too dark: ${worst.what} would be hard to read on it (${formatRatio(worst.ratio)}:1, minimum ${worst.minimum.toFixed(1)}:1). Try a lighter shade.` }
            : { ok: true, message: 'OK: text, links, buttons and forms stay readable on it.' };
    };

    return (
        <BackgroundColorField
            id="background_color"
            legend="Page background colour"
            noneLabel="None (the site’s page colour)"
            customLabel="A colour of its own"
            help="Behind the page’s content on the public site. Blocks with their own background keep it."
            value={value}
            onChange={onChange}
            error={error}
            suggestion={SUGGESTION}
            evaluate={evaluate}
            sample={(colour) => (
                // What sits on the page background: text, and a link in the darker brand shade.
                <div aria-hidden="true" className="rounded-md border border-gray-200 px-3 py-2 text-sm" style={{ backgroundColor: colour, color: PAGE_COLOURS.text }}>
                    Text and <span style={{ color: brandStrong(brandColor), textDecoration: 'underline' }}>a link</span>
                </div>
            )}
        />
    );
}
