import InputLabel from '@/Components/InputLabel';
import TextInput from '@/Components/TextInput';
import { HEX_PATTERN } from '@/utils/contrast';
import { useRef } from 'react';

/**
 * A colour picker and a hex field for the same #RRGGBB value (the brand colour in
 * Settings, a page's background colour). The field the readout describes is the
 * hex field, `${id}`; the picker is `${id}_picker`.
 */
export default function ColorInputs({ id, value, onChange, fallback, describedBy, invalid }) {
    // The picker needs a valid colour; while the hex field is being typed in, it keeps the last one.
    const lastValid = useRef(HEX_PATTERN.test(value) ? value : fallback);
    if (HEX_PATTERN.test(value)) lastValid.current = value;

    return (
        <div className="mt-3 flex flex-wrap items-end gap-4">
            <div>
                <InputLabel htmlFor={`${id}_picker`} value="Pick" />
                <input
                    id={`${id}_picker`}
                    type="color"
                    value={lastValid.current.toLowerCase()}
                    onChange={(e) => onChange(e.target.value.toUpperCase())}
                    className="mt-1 block h-10 w-16 cursor-pointer rounded-md border border-gray-400 bg-surface p-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2"
                />
            </div>
            <div>
                <InputLabel htmlFor={id} value="Hex code" />
                <TextInput
                    id={id}
                    value={value}
                    maxLength={7}
                    spellCheck={false}
                    autoComplete="off"
                    aria-describedby={describedBy}
                    aria-invalid={invalid}
                    onChange={(e) => onChange(e.target.value.trim())}
                    className="mt-1 block w-32 font-mono uppercase"
                />
            </div>
        </div>
    );
}
