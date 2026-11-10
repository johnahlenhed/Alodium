// The spacer block (App\Blocks\Types\SpacerBlock): empty space with a height
// and an optional background colour. No imports, so the Laravel tests can load
// it with Node (SiteThemeSyncTest checks the bounds against SpacerBlock's).

/** In px, as the server stores it. */
export const SPACER_HEIGHT = Object.freeze({ min: 2, max: 240, default: 32 });

const HEX = /^#[0-9a-fA-F]{6}$/;

/**
 * What to render for a spacer's data, checked again at render time: the height
 * in rem (px / 16, so it follows a visitor's font size setting, as the logo
 * height does) and the colour in lower case, or null for none. A missing height
 * is the default; an invalid value is replaced (the default height, no colour)
 * and listed in `problems` for a build warning. The colour goes into a style
 * attribute, so only an exact #rrggbb gets through: nothing else can reach the CSS.
 */
export function spacerStyle(data) {
    const problems = [];
    let height = data?.height ?? null;
    if (height === null) {
        height = SPACER_HEIGHT.default;
    } else if (!Number.isInteger(height) || height < SPACER_HEIGHT.min || height > SPACER_HEIGHT.max) {
        problems.push(`height ${JSON.stringify(height)} is not a whole number from ${SPACER_HEIGHT.min} to ${SPACER_HEIGHT.max}`);
        height = SPACER_HEIGHT.default;
    }

    let color = data?.background_color ?? null;
    if (color !== null && !(typeof color === 'string' && HEX.test(color))) {
        problems.push(`background colour ${JSON.stringify(color)} is not a #rrggbb colour`);
        color = null;
    }

    return { height: `${height / 16}rem`, color: color?.toLowerCase() ?? null, problems };
}

/** The style attribute: custom properties that blocks.css's .block-spacer reads. */
export function spacerStyleAttribute({ height, color }) {
    return color ? `--spacer-height: ${height}; --spacer-bg: ${color}` : `--spacer-height: ${height}`;
}
