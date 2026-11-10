import BackgroundColorField from '@/Components/BackgroundColorField';
import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import { SPACER_HEIGHT } from './spacer';

/** Offered when "A colour" is first chosen: the light warm grey the page background suggests too. */
const SUGGESTION = '#F5F1EA';

/**
 * Height (a slider, like the logo height in Settings) and an optional colour.
 * The colour has no contrast readout: nothing is written on a spacer.
 */
export default function SpacerBlockEditor({ blockId, data, errors, onChange }) {
    const height = Number.isInteger(data.height) ? data.height : SPACER_HEIGHT.default;
    const id = `${blockId}-height`;

    return (
        <div className="space-y-4">
            <div>
                <InputLabel htmlFor={id} value="Height" />
                <div className="mt-2 flex items-center gap-4">
                    <input
                        id={id}
                        type="range"
                        min={SPACER_HEIGHT.min}
                        max={SPACER_HEIGHT.max}
                        step={SPACER_HEIGHT.step}
                        value={height}
                        aria-describedby={`${id}-help`}
                        aria-valuetext={`${height} pixels`}
                        onChange={(e) => onChange({ height: Number(e.target.value) })}
                        className="w-full max-w-xs accent-brand-600"
                    />
                    <output htmlFor={id} className="w-14 flex-none text-sm tabular-nums text-gray-900">
                        {height} px
                    </output>
                    {height !== SPACER_HEIGHT.default && (
                        <button
                            type="button"
                            onClick={() => onChange({ height: SPACER_HEIGHT.default })}
                            className="flex-none text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        >
                            Reset to {SPACER_HEIGHT.default} px
                        </button>
                    )}
                </div>
                <p id={`${id}-help`} className="mt-1 text-sm text-gray-500">
                    {SPACER_HEIGHT.min} to {SPACER_HEIGHT.max} pixels. The usual space between blocks stays above and below it.
                </p>
                <InputError message={errors['data.height']} className="mt-1" />
            </div>
            <BackgroundColorField
                id={`${blockId}-background_color`}
                legend="Background colour"
                noneLabel="None (the page shows through)"
                customLabel="A colour"
                help="Fills the space across the full width of the page. Any colour works: nothing is written on a spacer, so there is no contrast check."
                value={data.background_color ?? null}
                onChange={(background_color) => onChange({ background_color })}
                error={errors['data.background_color']}
                suggestion={SUGGESTION}
            />
        </div>
    );
}
