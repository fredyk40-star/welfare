// Self-destructing service worker: replaces any previously registered
// worker and immediately unregisters itself so NO offline caching occurs.
self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        (async function () {
            // Delete every cache
            const keys = await caches.keys();
            await Promise.all(keys.map(function (k) { return caches.delete(k); }));
            // Remove this worker itself
            await self.registration.unregister();
        })()
    );
});

self.addEventListener('fetch', function () {
    // Never serve from cache — always go to network
});
