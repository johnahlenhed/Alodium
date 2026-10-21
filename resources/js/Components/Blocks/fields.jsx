import InputError from '@/Components/InputError';
import InputLabel from '@/Components/InputLabel';
import MediaPicker from '@/Components/MediaPicker';

export const inputClass =
    'mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500';

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
