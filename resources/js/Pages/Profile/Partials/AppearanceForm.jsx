import { useTheme } from '@/utils/theme';

const OPTIONS = [
    { value: 'system', label: 'Device setting', hint: 'Light or dark, following your operating system' },
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
];

/**
 * Light, dark or the device setting. Applies at once and is remembered in this
 * browser (localStorage, utils/theme.js), not on the server, so there is no
 * form to submit. The moon/sun button in the navigation bar does the same.
 */
export default function AppearanceForm({ className = '' }) {
    const { preference, setPreference } = useTheme();

    return (
        <section className={className}>
            <header>
                <h2 className="text-lg font-medium text-gray-900">Appearance</h2>
                <p className="mt-1 text-sm text-gray-600">
                    The colour theme of the admin. It is saved in this browser only.
                </p>
            </header>

            <fieldset className="mt-6">
                <legend className="sr-only">Theme</legend>
                <div className="space-y-3">
                    {OPTIONS.map((option) => (
                        <label key={option.value} className="flex items-start gap-3 text-sm text-gray-900">
                            <input
                                type="radio"
                                name="theme"
                                value={option.value}
                                checked={preference === option.value}
                                onChange={() => setPreference(option.value)}
                                className="mt-0.5 border-gray-400 text-brand-600 focus:ring-brand-500"
                            />
                            <span>
                                {option.label}
                                {option.hint && <span className="block text-gray-600">{option.hint}</span>}
                            </span>
                        </label>
                    ))}
                </div>
            </fieldset>
        </section>
    );
}
