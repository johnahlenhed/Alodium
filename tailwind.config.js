import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

// Colour tokens live in resources/css/theme.css as "R G B" channels; this only
// names them. <alpha-value> keeps opacity modifiers (bg-gray-500/75) working.
const token = (name) => `rgb(var(--color-${name}) / <alpha-value>)`;
const scale = (name) =>
    Object.fromEntries([50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((step) => [step, token(`${name}-${step}`)]));

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
        // Class names also live in plain modules (utils/publishing.js: the status badges).
        './resources/js/**/*.js',
    ],

    theme: {
        // Replaces Tailwind's palette instead of extending it, so a default
        // colour (indigo-600, slate-200 ...) can't slip back in. theme.check.mjs
        // reports any such class, because Tailwind silently skips it.
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            white: '#fff',
            black: '#000',
            // "gray" is kept as the name of the neutral scale: Tailwind's border
            // default and @tailwindcss/forms read colors.gray.
            gray: scale('gray'),
            brand: scale('brand'),
            success: scale('success'),
            warning: scale('warning'),
            danger: {
                ...scale('danger'),
                // DangerButton's fill: a fixed step in the light theme, a lighter one in the dark theme.
                fill: token('danger-fill'),
                'fill-hover': token('danger-fill-hover'),
                'fill-active': token('danger-fill-active'),
            },
            canvas: token('canvas'),
            surface: token('surface'),
            primary: {
                DEFAULT: token('primary'),
                hover: token('primary-hover'),
                active: token('primary-active'),
            },
            'on-primary': token('on-primary'),
            'on-danger': token('on-danger'),
            nav: {
                DEFAULT: token('nav'),
                hover: token('nav-hover'),
                accent: token('nav-accent'),
            },
            'on-nav': {
                DEFAULT: token('on-nav'),
                muted: token('on-nav-muted'),
            },
            // Only for @tailwindcss/forms, which reads colors.blue.600 for focus
            // rings and checked boxes on controls without their own classes.
            // Not for use in class names.
            blue: { 600: token('brand-600') },
        },
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            fontSize: {
                // The page heading under the navigation bar: grows with the screen,
                // from 20px on phones to 30px on wide screens. The rem part keeps it
                // growing with the browser's text size (zoom), not only the width.
                'page-title': ['clamp(1.25rem, 0.95rem + 1.1vw, 1.875rem)', { lineHeight: '1.25' }],
            },
            // A bare `ring` falls back to colors.blue.500, which isn't defined here.
            ringColor: {
                DEFAULT: token('brand-500'),
            },
            // The gap of ring-offset-* (focus rings): the surface, not white, in both themes.
            ringOffsetColor: {
                DEFAULT: 'rgb(var(--color-surface))',
            },
        },
    },

    plugins: [forms],
};
