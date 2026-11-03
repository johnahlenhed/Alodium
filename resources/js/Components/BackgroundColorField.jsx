import ColorInputs from '@/Components/ColorInputs';
import InputError from '@/Components/InputError';
import { HEX_PATTERN } from '@/utils/contrast';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { useRef } from 'react';

/**
 * A background colour that can be "none" (null) or a #RRGGBB colour of its own,
 * with the brand colour's picker (ColorInputs), a live readout from the rule the
 * server and the public site use, and a sample of what sits on it. Used for a
 * page's background (PageBackgroundField) and the header's (NavBackgroundField).
 * The result is given as text and an icon, never by colour alone.
 *
 * evaluate(colour) -> { ok, message } and sample(colour) -> element are only
 * called with a valid #RRGGBB colour; suggestion is the first colour offered.
 */
export default function BackgroundColorField({ id, legend, noneLabel, customLabel, help, value, onChange, error, suggestion, evaluate, sample }) {
    const custom = value !== null;
    const lastCustom = useRef(custom ? value : suggestion);
    if (custom) lastCustom.current = value;

    const valid = custom && HEX_PATTERN.test(value);
    const result = valid ? evaluate(value) : null;

    return (
        <fieldset>
            <legend className="text-sm font-medium text-gray-700">{legend}</legend>
            <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-900">
                <label className="flex items-center gap-2">
                    <input type="radio" name={`${id}_choice`} checked={!custom} onChange={() => onChange(null)} className="text-brand-600 focus:ring-brand-500" />
                    {noneLabel}
                </label>
                <label className="flex items-center gap-2">
                    <input type="radio" name={`${id}_choice`} checked={custom} onChange={() => onChange(lastCustom.current)} className="text-brand-600 focus:ring-brand-500" />
                    {customLabel}
                </label>
            </div>

            {custom && (
                <>
                    <ColorInputs id={id} value={value} onChange={onChange} fallback={suggestion} describedBy={`${id}_check`} invalid={!valid || !result.ok} />
                    <div className="mt-3 flex flex-wrap items-start gap-4">
                        {valid && sample(value)}
                        <p id={`${id}_check`} aria-live="polite" className="flex flex-1 items-start gap-2 text-sm text-gray-800">
                            {result?.ok ? (
                                <CircleCheck className="mt-0.5 h-4 w-4 shrink-0 text-success-700" aria-hidden="true" />
                            ) : (
                                <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning-600" aria-hidden="true" />
                            )}
                            <span>{valid ? result.message : `Enter # followed by 6 hexadecimal digits, for example ${suggestion}.`}</span>
                        </p>
                    </div>
                </>
            )}
            <p className="mt-2 text-sm text-gray-500">{help}</p>
            <InputError message={error} className="mt-2" />
        </fieldset>
    );
}
