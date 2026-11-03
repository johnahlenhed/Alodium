import ColorInputs from '@/Components/ColorInputs';
import InputError from '@/Components/InputError';
import { brandStrong, formatRatio, HEX_PATTERN, PAGE_COLOURS, worstPageBackgroundCheck } from '@/utils/contrast';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { useRef } from 'react';

/** Suggested when "A colour of its own" is first chosen: a light warm grey that passes with any brand. */
const SUGGESTION = '#F5F1EA';

/**
 * The page's background colour (pages.background_color): none (the site's page
 * colour), or a colour of its own, with the same picker as the brand colour and
 * a live readout of the rule the server and the public site use. The result is
 * given as text and an icon, never by colour alone.
 */
export default function PageBackgroundField({ value, onChange, error, brandColor }) {
    const custom = value !== null;
    const lastCustom = useRef(custom ? value : SUGGESTION);
    if (custom) lastCustom.current = value;

    const valid = custom && HEX_PATTERN.test(value);
    const worst = valid ? worstPageBackgroundCheck(value, brandColor) : null;

    return (
        <fieldset>
            <legend className="text-sm font-medium text-gray-700">Page background colour</legend>
            <div className="mt-2 flex flex-wrap gap-x-6 gap-y-2 text-sm text-gray-900">
                <label className="flex items-center gap-2">
                    <input type="radio" name="background_choice" checked={!custom} onChange={() => onChange(null)} className="text-brand-600 focus:ring-brand-500" />
                    None (the site&rsquo;s page colour)
                </label>
                <label className="flex items-center gap-2">
                    <input type="radio" name="background_choice" checked={custom} onChange={() => onChange(lastCustom.current)} className="text-brand-600 focus:ring-brand-500" />
                    A colour of its own
                </label>
            </div>

            {custom && (
                <>
                    <ColorInputs id="background_color" value={value} onChange={onChange} fallback={SUGGESTION} describedBy="background_color_check" invalid={!valid || worst !== null} />
                    <div className="mt-3 flex flex-wrap items-start gap-4">
                        {valid && (
                            // What sits on the page background: text, and a link in the darker brand shade.
                            <div aria-hidden="true" className="rounded-md border border-gray-200 px-3 py-2 text-sm" style={{ backgroundColor: value, color: PAGE_COLOURS.text }}>
                                Text and <span style={{ color: brandStrong(brandColor), textDecoration: 'underline' }}>a link</span>
                            </div>
                        )}
                        <p id="background_color_check" aria-live="polite" className="flex flex-1 items-start gap-2 text-sm text-gray-800">
                            {!valid ? (
                                <>
                                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning-600" aria-hidden="true" />
                                    <span>Enter # followed by 6 hexadecimal digits, for example {SUGGESTION}.</span>
                                </>
                            ) : worst ? (
                                <>
                                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-warning-600" aria-hidden="true" />
                                    <span>
                                        Too dark: {worst.what} would be hard to read on it ({formatRatio(worst.ratio)}:1, minimum{' '}
                                        {worst.minimum.toFixed(1)}:1). Try a lighter shade.
                                    </span>
                                </>
                            ) : (
                                <>
                                    <CircleCheck className="mt-0.5 h-4 w-4 shrink-0 text-success-700" aria-hidden="true" />
                                    <span>OK: text, links, buttons and forms stay readable on it.</span>
                                </>
                            )}
                        </p>
                    </div>
                </>
            )}
            <p className="mt-2 text-sm text-gray-500">
                Behind the page&rsquo;s content on the public site. Blocks with their own background keep it.
            </p>
            <InputError message={error} className="mt-2" />
        </fieldset>
    );
}
