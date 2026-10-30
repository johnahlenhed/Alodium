// The admin's light/dark theme (resources/css/theme.css: the dark tokens are
// under [data-theme='dark'] on <html>).
//
// The choice is per browser, in localStorage: 'light' or 'dark'. Without one
// ('system') the OS setting (prefers-color-scheme) decides, and a change to it
// applies at once. The inline script in resources/views/app.blade.php does the
// same before the first paint, so a page never flashes the wrong theme; keep
// the two in sync (theme.check.mjs compares the storage key).
import { useSyncExternalStore } from 'react';

export const STORAGE_KEY = 'alodium-theme';

/** 'light' or 'dark' as stored; anything else (nothing, junk) is 'system'. */
export function parsePreference(value) {
    return value === 'light' || value === 'dark' ? value : 'system';
}

/** The theme to show: the preference, or the OS setting for 'system'. */
export function resolveTheme(preference, systemDark) {
    return preference === 'system' ? (systemDark ? 'dark' : 'light') : preference;
}

const inBrowser = typeof window !== 'undefined';
const darkQuery = inBrowser && window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

// localStorage can throw (blocked site data, some private modes): then the
// choice lasts until the page is closed, and the OS setting is the default.
function readStored() {
    try {
        return parsePreference(window.localStorage.getItem(STORAGE_KEY));
    } catch {
        return 'system';
    }
}

function writeStored(preference) {
    try {
        if (preference === 'system') window.localStorage.removeItem(STORAGE_KEY);
        else window.localStorage.setItem(STORAGE_KEY, preference);
    } catch {
        // Not saved; still applied.
    }
}

let preference = inBrowser ? readStored() : 'system';
const listeners = new Set();

export function currentTheme() {
    return resolveTheme(preference, Boolean(darkQuery?.matches));
}

function changed() {
    document.documentElement.dataset.theme = currentTheme();
    listeners.forEach((listener) => listener());
}

/** 'system', 'light' or 'dark': applied at once and remembered in this browser. */
export function setPreference(next) {
    preference = parsePreference(next);
    writeStored(preference);
    changed();
}

function subscribe(listener) {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

if (inBrowser) {
    darkQuery?.addEventListener?.('change', () => {
        if (preference === 'system') changed();
    });
    // A choice made in another tab.
    window.addEventListener('storage', (event) => {
        if (event.key === STORAGE_KEY || event.key === null) {
            preference = readStored();
            changed();
        }
    });
}

/** { preference: 'system'|'light'|'dark', theme: 'light'|'dark', setPreference } */
export function useTheme() {
    const snapshot = useSyncExternalStore(subscribe, () => `${preference} ${currentTheme()}`);
    const [current, theme] = snapshot.split(' ');
    return { preference: current, theme, setPreference };
}
