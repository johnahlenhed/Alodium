import ColorInputs from '@/Components/ColorInputs';
import InputError from '@/Components/InputError';
import { contrastWithWhite, formatRatio } from '@/utils/contrast';
import { CircleCheck, TriangleAlert } from 'lucide-react';

/**
 * A colour picker and a hex field for the same value, with a live contrast
 * readout. The result is given as text and an icon, never by colour alone.
 */
export default function BrandColorField({ value, onChange, error, minContrast }) {
    const ratio = contrastWithWhite(value);
    const ok = ratio !== null && ratio >= minContrast;

    return (
        <fieldset>
            <legend className="text-sm font-medium text-gray-700">Brand colour</legend>
            <p className="mt-1 text-sm text-gray-500">
                Used for buttons, links and the hero. White text sits on it, so it has to be dark enough.
            </p>

            <ColorInputs id="brand_color" value={value} onChange={onChange} fallback="#1B2A4A" describedBy="brand_color_contrast" invalid={!ok} />

            <p id="brand_color_contrast" aria-live="polite" className="mt-3 flex items-start gap-2 text-sm text-gray-800">
                {ratio === null ? (
                    <>
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning-600" aria-hidden="true" />
                        <span>Enter # followed by 6 hexadecimal digits, for example #1B2A4A.</span>
                    </>
                ) : ok ? (
                    <>
                        <CircleCheck className="mt-0.5 h-4 w-4 shrink-0 text-success-700" aria-hidden="true" />
                        <span>Contrast with white: {formatRatio(ratio)}:1, OK</span>
                    </>
                ) : (
                    <>
                        <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning-600" aria-hidden="true" />
                        <span>
                            Contrast with white: {formatRatio(ratio)}:1, too low (minimum {minContrast.toFixed(1)}:1). Try a
                            darker shade.
                        </span>
                    </>
                )}
            </p>
            <InputError message={error} className="mt-2" />
        </fieldset>
    );
}
