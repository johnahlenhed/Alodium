import { Component } from 'react';

/**
 * Keeps one part of a page that fails to render from taking the whole page
 * down: React unmounts everything on an uncaught render error, which left the
 * dashboard blank when its Site status got data in a shape it didn't expect
 * (admin assets from an older release than the server's PHP). Shows a short
 * note in its place instead.
 *
 * A caught error never reaches Sentry's global handler, so it is reported here,
 * when Sentry is on (production; see app.jsx).
 */
export default class SectionBoundary extends Component {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error, info) {
        if (typeof document !== 'undefined' && document.querySelector('meta[name="sentry-dsn"]')) {
            import('@sentry/react')
                .then((Sentry) => Sentry.captureException(error, { extra: { componentStack: info?.componentStack, section: this.props.name } }))
                .catch(() => {});
        }
    }

    render() {
        if (!this.state.failed) return this.props.children;

        return (
            <section role="alert" className={`rounded-lg bg-surface p-5 text-sm text-gray-700 shadow-sm ${this.props.className ?? ''}`}>
                <p>
                    <span className="font-semibold text-gray-900">{this.props.name}</span> couldn’t be shown. Reload the page;
                    if it keeps happening, tell your developer (the error has been reported).
                </p>
            </section>
        );
    }
}
