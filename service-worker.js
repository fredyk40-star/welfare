// GYF Welfare - Cache-first service worker with offline fallback.
// Caches the app shell on install, serves from cache first, falls back to network.
// Provides offline capability for the PWA.

const CACHE_NAME = 'gyf-welfare-v2';
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

self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function(cache) {
                return cache.addAll(APP_SHELL);
            })
            .then(function() {
                return self.skipWaiting();
            })
            .catch(function(err) {
                console.warn('SW install failed:', err);
            })
    );
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys.filter(function(key) {
                    return key !== CACHE_NAME;
                }).map(function(key) {
                    return caches.delete(key);
                })
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

self.addEventListener('fetch', function(event) {
    var request = event.request;
    var url = new URL(request.url);

    // Skip non-GET requests
    if (request.method !== 'GET') return;

    // For same-origin requests, try cache first, then network
    if (url.origin === self.location.origin) {
        event.respondWith(
            caches.match(request).then(function(cached) {
                if (cached) {
                    return cached;
                }
                return fetch(request).then(function(response) {
                    if (response && response.status === 200) {
                        var clone = response.clone();
                        caches.open(CACHE_NAME).then(function(cache) {
                            cache.put(request, clone);
                        });
                    }
                    return response;
                }).catch(function() {
                    // Offline fallback for navigation requests
                    if (request.mode === 'navigate') {
                        return caches.match('/index.html');
                    }
                });
            })
        );
    }
});
