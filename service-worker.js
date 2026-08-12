// GYF Welfare - Network-only service worker.
// Intentionally does NOT cache anything. Every request goes straight to the
// network. If the user is offline, the fetch fails and the browser shows its
// standard network error. No offline support by design.
self.addEventListener('install', function (event) {
    // Activate immediately instead of waiting for old SWs to close.
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    // Take control of all clients right away.
    event.waitUntil(self.clients.claim());
});

// Pass every fetch directly to the network. No cache reads/writes.
self.addEventListener('fetch', function (event) {
    // Let the browser handle it natively (includes normal network errors offline).
    return;
});
