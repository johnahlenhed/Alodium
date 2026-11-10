// The spacer block (App\Blocks\Types\SpacerBlock): empty space with a height
// and an optional background colour. No imports, so the Laravel tests can load
// it with Node (SiteThemeSyncTest checks the bounds against SpacerBlock's).

/** In px, as the server stores it. */
export const SPACER_HEIGHT = Object.freeze({ min: 2, max: 240, default: 32 });

/** The colour's opacity, in percent: 100 is fully opaque (and what a spacer stored without it gets). */
export const SPACER_OPACITY = Object.freeze({ min: 0, max: 100, default: 100 });

const HEX = /^#[0-9a-fA-F]{6}$/;

/**
 * What to render for a spacer's data, checked again at render time: the height
 * in rem (px / 16, so it follows a visitor's font size setting, as the logo
 * height does), the colour in lower case, or null for none, and the opacity as
 * a CSS number (0.6 for 60), or null when it is 100 or there is no colour. A
 * missing height or opacity is the default; an invalid value is replaced (the
 * default height, no colour, 100) and listed in `problems` for a build warning. The colour goes into a style
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

    let opacity = data?.opacity ?? null;
    if (opacity === null) {
        opacity = SPACER_OPACITY.default;
    } else if (!Number.isInteger(opacity) || opacity < SPACER_OPACITY.min || opacity > SPACER_OPACITY.max) {
        problems.push(`opacity ${JSON.stringify(opacity)} is not a whole number from ${SPACER_OPACITY.min} to ${SPACER_OPACITY.max}`);
        opacity = SPACER_OPACITY.default;
    }

    return {
        height: `${height / 16}rem`,
        color: color?.toLowerCase() ?? null,
        // Only with a colour: on an empty, uncoloured div it would change nothing.
        opacity: color !== null && opacity !== SPACER_OPACITY.max ? opacity / 100 : null,
        problems,
    };
}

/**
 * The style attribute: custom properties that blocks.css's .block-spacer reads.
 * The opacity is CSS opacity on the element rather than an rgba() colour: the
 * spacer is empty, so the two look the same, and the colour stays one #rrggbb.
 */
export function spacerStyleAttribute({ height, color, opacity }) {
    return [`--spacer-height: ${height}`, color && `--spacer-bg: ${color}`, opacity !== null && `--spacer-opacity: ${opacity}`].filter(Boolean).join('; ');
}
