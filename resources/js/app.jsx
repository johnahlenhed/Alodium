import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Sentry for browser errors. The DSN comes from the page (resources/views/app.blade.php),
// which only includes it when Laravel runs with APP_ENV=production. Read at runtime
// rather than baked in at build time (VITE_*), because the assets are built locally:
// that way the same build never reports errors from a local or test environment.
const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;
const sentryDsn = meta('sentry-dsn');

// Loaded as a separate chunk only when needed, so local sessions never download it.
if (sentryDsn) {
    import('@sentry/react').then((Sentry) =>
        Sentry.init({
            dsn: sentryDsn,
            environment: meta('sentry-environment'),
            integrations: [Sentry.browserTracingIntegration()],
            tracesSampleRate: Number(meta('sentry-traces-sample-rate') ?? 0.1),
        }),
    );
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob('./Pages/**/*.jsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});
