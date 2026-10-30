import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { contrastWithWhite, formatRatio, HEX_PATTERN } from '@/utils/contrast';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { useRef } from 'react';

/**
 * A colour picker and a hex field for the same value, with a live contrast
 * readout. The result is given as text and an icon, never by colour alone.
 */
export default function BrandColorField({ value, onChange, error, minContrast }) {
    const ratio = contrastWithWhite(value);
    const ok = ratio !== null && ratio >= minContrast;

    // The picker needs a valid colour; while the hex field is being typed in, it keeps the last one.
    const lastValid = useRef(HEX_PATTERN.test(value) ? value : '#1B2A4A');
    if (HEX_PATTERN.test(value)) lastValid.current = value;

    return (
        <fieldset>
            <legend className="text-sm font-medium text-gray-700">Brand colour</legend>
            <p className="mt-1 text-sm text-gray-500">
                Used for buttons, links and the hero. White text sits on it, so it has to be dark enough.
            </p>

            <div className="mt-3 flex flex-wrap items-end gap-4">
                <div>
                    <InputLabel htmlFor="brand_color_picker" value="Pick" />
                    <input
                        id="brand_color_picker"
                        type="color"
                        value={lastValid.current.toLowerCase()}
                        onChange={(e) => onChange(e.target.value.toUpperCase())}
                        className="mt-1 block h-10 w-16 cursor-pointer rounded-md border border-gray-400 bg-surface p-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                    />
                </div>
                <div>
                    <InputLabel htmlFor="brand_color" value="Hex code" />
                    <TextInput
                        id="brand_color"
                        value={value}
                        maxLength={7}
                        spellCheck={false}
                        autoComplete="off"
                        aria-describedby="brand_color_contrast"
                        aria-invalid={!ok}
                        onChange={(e) => onChange(e.target.value.trim())}
                        className="mt-1 block w-32 font-mono uppercase"
                    />
                </div>
            </div>

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
