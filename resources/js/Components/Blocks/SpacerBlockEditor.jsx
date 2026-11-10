import BackgroundColorField from '@/Components/BackgroundColorField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { SPACER_HEIGHT, SPACER_OPACITY } from './spacer';

/** Offered when "A colour" is first chosen: the light warm grey the page background suggests too. */
const SUGGESTION = '#F5F1EA';

/** The stored whole number, or the default when it is missing (a spacer saved before the field). */
const wholeOr = (value, fallback) => (Number.isInteger(value) ? value : fallback);

/**
 * A slider with its value, a reset to the default and help text, as for the
 * logo height in Settings. `bounds` is SPACER_HEIGHT or SPACER_OPACITY.
 */
function Slider({ id, label, bounds, value, unit, valueText, help, error, onChange }) {
    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <div className="mt-2 flex items-center gap-4">
                <input
                    id={id}
                    type="range"
                    min={bounds.min}
                    max={bounds.max}
                    step={bounds.step}
                    value={value}
                    aria-describedby={`${id}-help`}
                    aria-valuetext={valueText}
                    onChange={(e) => onChange(Number(e.target.value))}
                    className="w-full max-w-xs accent-brand-600"
                />
                <output htmlFor={id} className="w-14 flex-none text-sm tabular-nums text-gray-900">
                    {value} {unit}
                </output>
                {value !== bounds.default && (
                    <button
                        type="button"
                        onClick={() => onChange(bounds.default)}
                        className="flex-none text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                    >
                        Reset to {bounds.default} {unit}
                    </button>
                )}
            </div>
            <p id={`${id}-help`} className="mt-1 text-sm text-gray-500">
                {help}
            </p>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * Height, an optional colour and, only with a colour, its opacity. The colour
 * has no contrast readout: nothing is written on a spacer.
 */
export default function SpacerBlockEditor({ blockId, data, errors, onChange }) {
    const height = wholeOr(data.height, SPACER_HEIGHT.default);
    const opacity = wholeOr(data.opacity, SPACER_OPACITY.default);
    const color = data.background_color ?? null;

    return (
        <div className="space-y-4">
            <Slider
                id={`${blockId}-height`}
                label="Height"
                bounds={SPACER_HEIGHT}
                value={height}
                unit="px"
                valueText={`${height} pixels`}
                help={`${SPACER_HEIGHT.min} to ${SPACER_HEIGHT.max} pixels. The usual space between blocks stays above and below it.`}
                error={errors['data.height']}
                onChange={(value) => onChange({ height: value })}
            />
            <BackgroundColorField
                id={`${blockId}-background_color`}
                legend="Background colour"
                noneLabel="None (the page shows through)"
                customLabel="A colour"
                help="Fills the space across the full width of the page. Any colour works: nothing is written on a spacer, so there is no contrast check."
                value={color}
                onChange={(background_color) => onChange({ background_color })}
                error={errors['data.background_color']}
                suggestion={SUGGESTION}
            />
            {/* Without a colour there is nothing to fade; the server then stores the default. */}
            {color !== null && (
                <Slider
                    id={`${blockId}-opacity`}
                    label="Colour opacity"
                    bounds={SPACER_OPACITY}
                    value={opacity}
                    unit="%"
                    valueText={`${opacity} percent`}
                    help={`${SPACER_OPACITY.min}% lets the page show through completely, ${SPACER_OPACITY.max}% is the colour as chosen.`}
                    error={errors['data.opacity']}
                    onChange={(value) => onChange({ opacity: value })}
                />
            )}
        </div>
    );
}
