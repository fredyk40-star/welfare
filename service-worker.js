// GYF Welfare - Service worker.
// Cache-first for the STATIC app shell ONLY (icons/css/js). All dynamic routes
// (.php, /api/, /treasurer/, /member/) and navigations are served network-first
// and are NEVER cached, so the treasurer dashboard / member pages always reflect
// the latest TiDB Cloud data (e.g. a just-recorded payment) instead of a stale
// copy frozen in the service-worker cache.

const CACHE_NAME = 'gyf-welfare-v3';

// Static app shell - versioned by CACHE_NAME (bump to invalidate).
const APP_SHELL = [
    '/',
    '/index.html',
    '/manifest.json',
    '/assets/bootstrap/css/bootstrap.min.css',
    '/assets/css/style.css',
    '/assets/js/main.js',
    '/assets/js/qrcode.min.js',
    '/assets/js/validation.js',
    '/assets/js/slideshow.js',
    '/assets/js/modal-failsafe.js',
    '/assets/bootstrap/js/bootstrap.bundle.min.js',
    '/assets/images/logo.png',
    '/assets/icons/icon-192x192.png',
    '/assets/icons/icon-512x512.png',
];

// Dynamic routes that must ALWAYS hit the network and never be cached.
function isDynamicPath(pathname) {
    return (
        pathname.length > 0 &&
        (
            pathname.endsWith('.php') ||
            pathname.indexOf('/api/') === 0 ||
            pathname.indexOf('/treasurer/') === 0 ||
            pathname.indexOf('/member/') === 0
        )
    );
}

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function (cache) {
                return cache.addAll(APP_SHELL);
            })
            .then(function () {
                return self.skipWaiting();
            })
            .catch(function (err) {
                console.warn('SW install failed:', err);
            })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(function (key) {
                    return key !== CACHE_NAME;
                }).map(function (key) {
                    return caches.delete(key);
                })
            );
        }).then(function () {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function (event) {
    // Only intercept GET requests.
    if (event.request.method !== 'GET') {
        return;
    }

    var url = new URL(event.request.url);

    // Only handle same-origin requests.
    if (url.origin !== self.location.origin) {
        return;
    }

    // Network-first (never cached) for dynamic content and navigations.
    if (isDynamicPath(url.pathname) || event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(function () {
                // Offline fallback for navigations: serve the app shell.
                if (event.request.mode === 'navigate') {
                    return caches.match('/index.html');
                }
            })
        );
        return;
    }

    // Cache-first for static app-shell assets.
    event.respondWith(
        caches.match(event.request).then(function (cached) {
            if (cached) {
                return cached;
            }
            return fetch(event.request).then(function (response) {
                if (response && response.status === 200) {
                    var clone = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(event.request, clone);
                    });
                }
                return response;
            }).catch(function () {
                if (event.request.mode === 'navigate') {
                    return caches.match('/index.html');
                }
            });
        })
    );
});
