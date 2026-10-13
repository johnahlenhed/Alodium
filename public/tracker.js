(function () {
    'use strict';

    // Everything is wrapped in try/catch: the tracker must never break the host site.
    try {
        var API = (window.ALODIUM_TRACKER_URL || '/api').replace(/\/$/, '');
        var UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        var startedAt = Date.now();
        var visitId = null;
        var token = null;
        var maxScroll = 0;

        function send(method, path, body) {
            return fetch(API + path, {
                method: method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
                // Lets the request outlive the page (unload, outbound navigation).
                keepalive: true,
            });
        }

        // Capture UTM params, then strip them from the visible URL (the page URL
        // stays shareable/clean, and the stored `url` is pathname-only anyway).
        var params = new URLSearchParams(window.location.search);
        var payload = {
            url: window.location.pathname,
            referrer: document.referrer || null,
            device_type: window.innerWidth <= 768 ? 'mobile' : 'desktop',
            screen_width: window.innerWidth,
            language: navigator.language || null,
        };
        var hadUtm = false;
        UTM_KEYS.forEach(function (key) {
            payload[key] = params.get(key) || null;
            if (params.has(key)) {
                params.delete(key);
                hadUtm = true;
            }
        });
        if (hadUtm) {
            var query = params.toString();
            history.replaceState(
                history.state,
                '',
                window.location.pathname + (query ? '?' + query : '') + window.location.hash
            );
        }

        send('POST', '/collect', payload)
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                if (data && data.visit_id) {
                    visitId = data.visit_id;
                    token = data.token || null;
                }
            })
            .catch(function () {});

        function updateScroll() {
            try {
                var scrollable = document.documentElement.scrollHeight - window.innerHeight;
                // A page that fits the viewport has been fully seen.
                var pct = scrollable > 0 ? (window.scrollY / scrollable) * 100 : 100;
                maxScroll = Math.max(maxScroll, Math.min(100, Math.round(pct)));
            } catch (e) {}
        }
        window.addEventListener('scroll', updateScroll, { passive: true });
        updateScroll();

        document.addEventListener('visibilitychange', function () {
            try {
                if (document.visibilityState !== 'hidden' || !visitId || !token) return;
                updateScroll();
                send('PATCH', '/collect/' + visitId, {
                    token: token,
                    duration: Math.round((Date.now() - startedAt) / 1000),
                    scroll_depth: maxScroll,
                }).catch(function () {});
            } catch (e) {}
        });

        document.addEventListener('click', function (e) {
            try {
                var link = e.target && e.target.closest ? e.target.closest('a') : null;
                if (!link || !visitId || !token) return;
                if (link.protocol !== 'http:' && link.protocol !== 'https:') return;
                if (!link.hostname || link.hostname === window.location.hostname) return;
                send('POST', '/events', {
                    visit_id: visitId,
                    token: token,
                    type: 'outbound_click',
                    payload: { url: link.href },
                }).catch(function () {});
            } catch (err) {}
        });
    } catch (e) {}
})();
