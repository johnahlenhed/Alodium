import { useTheme } from '@/utils/theme';
import { Moon, Sun } from 'lucide-react';

/**
 * Light/dark switch for the navigation bar (desktop and mobile). A toggle
 * button: the name stays "Dark mode", aria-pressed says whether it is on.
 * Choosing sets an explicit preference; "Use the device setting" is on the
 * Profile page (Partials/AppearanceForm.jsx).
 */
export default function ThemeToggle({ className = '' }) {
    const { theme, setPreference } = useTheme();
    const dark = theme === 'dark';
    const Icon = dark ? Sun : Moon;

    return (
        <button
            type="button"
            aria-pressed={dark}
            aria-label="Dark mode"
            title={dark ? 'Switch to light mode' : 'Switch to dark mode'}
            onClick={() => setPreference(dark ? 'light' : 'dark')}
            className={
                'inline-flex items-center justify-center rounded-md p-2 text-on-nav-muted transition duration-150 ease-in-out hover:bg-nav-hover hover:text-on-nav focus:outline-none focus-visible:bg-nav-hover focus-visible:text-on-nav focus-visible:ring-2 focus-visible:ring-on-nav-muted ' +
                className
            }
        >
            <Icon className="h-5 w-5" aria-hidden="true" />
        </button>
    );
}
