// GYF Welfare - Header Common Scripts
// Bootstrap CSS + JS CDN fallback (service worker removed to fix navbar toggle)

(function () {
    'use strict';

    const BOOTSTRAP_CSS = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
    const BOOTSTRAP_JS  = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';

    // Bootstrap CSS CDN fallback
    const css = document.querySelector('link[href*="bootstrap.min.css"]');
    if (css && !css.sheet) {
        const fallback = document.createElement('link');
        fallback.rel = 'stylesheet';
        fallback.href = BOOTSTRAP_CSS;
        document.head.appendChild(fallback);
    }

    // Bootstrap JS recovery: if the local bootstrap.bundle.min.js failed to
    // load/execute (e.g. a stale or missing asset served as HTML on mobile /
    // Vercel), window.bootstrap will be undefined. Load it from the CDN so the
    // navbar toggler and all data-bs-* handlers work. This also prevents the
    // "Unexpected token '<'" parse error that occurs when the JS request
    // returns an HTML response instead of JavaScript.
    function ensureBootstrapJs(cb) {
        if (window.bootstrap && typeof bootstrap.Collapse !== 'undefined') {
            if (cb) cb();
            return;
        }
        const s = document.createElement('script');
        s.src = BOOTSTRAP_JS;
        s.onload = function () { if (cb) cb(); };
        s.onerror = function () { if (cb) cb(); };
        document.head.appendChild(s);
    }

    // Fail-safe: ensure the navbar collapse toggle works even if
    // bootstrap.bundle.min.js was stale/missing or the page was restored
    // from bfcache (back/forward cache) without re-running the bundle init.
    function initNavbarToggle() {
        const toggler = document.querySelector('.navbar-toggler');
        const target = toggler && toggler.getAttribute('data-bs-target');
        if (!toggler || !target) return;
        const menu = document.querySelector(target);
        if (!menu) return;

        // Bootstrap is present: let it handle it (plugin already attached).
        if (window.bootstrap && typeof bootstrap.Collapse !== 'undefined') return;

        // No Bootstrap: wire a manual toggle so the menu is never dead.
        if (toggler.dataset.failsafeBound) return;
        toggler.dataset.failsafeBound = '1';
        toggler.addEventListener('click', function (e) {
            e.preventDefault();
            menu.classList.toggle('show');
        });
    }

    // Run on initial load and on every bfcache restore.
    document.addEventListener('DOMContentLoaded', function () {
        ensureBootstrapJs(initNavbarToggle);
    });
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { ensureBootstrapJs(initNavbarToggle); }
    });
})();
