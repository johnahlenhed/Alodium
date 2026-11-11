(function () {
    'use strict';

    // Everything is wrapped in try/catch: the tracker must never break the host site.
    try {
        var API = (window.ALODIUM_TRACKER_URL || '/api').replace(/\/$/, '');
        // Sent: these three only, cleaned as App\Support\UtmValue does. utm_term and
        // utm_content are removed from the address bar too, but never read or sent:
        // they are where subscriber-level identifiers usually end up.
        var UTM_SENT = ['utm_source', 'utm_medium', 'utm_campaign'];
        var UTM_REMOVED = ['utm_term', 'utm_content'];
        var UTM_MAX_LENGTH = 100;
        var startedAt = Date.now();
        var visitId = null;
        var token = null;
        var maxScroll = 0;

        // A label, cleaned before it leaves the browser (the server cleans it again):
        // invisible characters removed, whitespace collapsed, lowercase, at most 100
        // characters; null when empty or when it looks like an email address or a
        // personal id. Same rules and examples as App\Support\UtmValue
        // (tests/Fixtures/utm-values.json). No /u regexes: a syntax error an old
        // browser can't parse would stop the whole tracker.
        function cleanUtm(value) {
            if (typeof value !== 'string') return null;
            value = value
                .replace(/[\u0000-\u0008\u000E-\u001F\u007F-\u009F\u00AD\u200B-\u200F\u202A-\u202E\u2060-\u2064\uFEFF]/g, '')
                .replace(/[\s\u00A0\u1680\u2000-\u200A\u2028\u2029\u202F\u205F\u3000]+/g, ' ')
                .trim()
                .toLowerCase();
            if (value === '' || value.indexOf('@') !== -1 || /\d{7,}/.test(value)) return null;
            var words = value.split(/[^a-z0-9]+/);
            for (var i = 0; i < words.length; i++) {
                var word = words[i];
                var mixed = /[a-z]/.test(word) && /\d/.test(word);
                if (word.length >= 24 || (mixed && word.length >= 16) || (mixed && word.length >= 8 && /^[0-9a-f]+$/.test(word))) return null;
            }
            // By code points, as the server counts (an emoji is one character).
            return Array.from(value).slice(0, UTM_MAX_LENGTH).join('').replace(/\s+$/, '');
        }

        function send(method, path, body) {
            return fetch(API + path, {
                method: method,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(body),
                // Lets the request outlive the page (unload, outbound navigation).
                keepalive: true,
            });
        }

        // Capture the UTM labels, then strip every UTM parameter from the visible URL
        // (the page URL stays shareable and clean; the stored `url` is the path only).
        var params = new URLSearchParams(window.location.search);
        var payload = {
            url: window.location.pathname,
            referrer: document.referrer || null,
            device_type: window.innerWidth <= 768 ? 'mobile' : 'desktop',
            screen_width: window.innerWidth,
            language: navigator.language || null,
        };
        var hadUtm = false;
        UTM_SENT.forEach(function (key) {
            payload[key] = cleanUtm(params.get(key));
        });
        UTM_SENT.concat(UTM_REMOVED).forEach(function (key) {
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
