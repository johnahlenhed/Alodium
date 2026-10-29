import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import MediaPicker from '@/Components/MediaPicker';

export const inputClass =
    'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-brand-500 focus:ring-brand-500';

/** Labelled text input or textarea with its hint and server error. */
export function Field({ id, label, value, onChange, error, hint, multiline = false, ...props }) {
    const Control = multiline ? 'textarea' : 'input';
    const hintId = hint ? `${id}-hint` : undefined;

    return (
        <div>
            <InputLabel htmlFor={id} value={label} />
            <Control
                id={id}
                className={inputClass}
                value={value ?? ''}
                onChange={(e) => onChange(e.target.value)}
                aria-describedby={hintId}
                aria-invalid={error ? true : undefined}
                {...(multiline ? { rows: 3 } : { type: 'text' })}
                {...props}
            />
            {hint && (
                <p id={hintId} className="mt-1 text-sm text-gray-500">
                    {hint}
                </p>
            )}
            <InputError message={error} className="mt-1" />
        </div>
    );
}

/**
 * The stored value for an image in a block: the 1200px "medium" conversion when
 * it has been generated, otherwise the original, as a path starting with /storage/.
 * Relative, so it works on the public site too (Astro makes it absolute), and the
 * admin preview works because the admin and the files share an origin.
 */
export function blockImageValue(item) {
    const url = item.medium_url ?? item.url;
    try {
        const { pathname } = new URL(url, window.location.origin);
        return pathname.startsWith('/storage/') ? pathname : url;
    } catch {
        return url;
    }
}

/** The background choices, as App\Blocks\BlockType::VARIANTS; each type offers a subset. */
const VARIANT_LABELS = {
    default: 'None',
    light: 'Light (a pale tint of the brand colour)',
    brand: 'Brand colour (white text)',
    dark: 'Dark (white text)',
};

/**
 * The block's background (data.variant). `variants` are the ones this block
 * type offers; `defaultLabel` names what "default" looks like for it.
 */
export function VariantField({ id, variants, defaultLabel = VARIANT_LABELS.default, value, onChange, error }) {
    return (
        <div>
            <InputLabel htmlFor={id} value="Background" />
            <select id={id} className={inputClass} value={value || 'default'} onChange={(e) => onChange(e.target.value)}>
                {variants.map((variant) => (
                    <option key={variant} value={variant}>
                        {variant === 'default' ? defaultLabel : VARIANT_LABELS[variant]}
                    </option>
                ))}
            </select>
            <InputError message={error} className="mt-1" />
        </div>
    );
}

export function BlockImageField({ label, description, value, onChange, error }) {
    return (
        <MediaPicker
            value={value}
            onChange={onChange}
            error={error}
            toValue={blockImageValue}
            label={label}
            description={description}
        />
    );
}
