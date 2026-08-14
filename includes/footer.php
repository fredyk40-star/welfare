    </div> <!-- Close container from header -->
    
    <!-- Footer -->
    <footer class="mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p class="mb-0" style="color: var(--text-muted);">&copy; <?php echo date('Y'); ?> GYF Ministry & Prayer Camp. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <small style="color: var(--text-muted);">
                        Welfare Management System v1.0 | 
                        <?php if (isLoggedIn()): ?>
                            Logged in as: <strong style="color: var(--text-secondary);"><?php echo htmlspecialchars($_SESSION['user_id'] ?? ''); ?></strong>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Scripts (version-busted so stale cached Bootstrap can never execute) -->
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js?v=20260807"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/qrcode.min.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js?v=20260807"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/validation.js?v=20260807"></script>
    
    <!-- Online Status Check -->
<script nonce="<?php echo CSP_NONCE; ?>">
        // Check online status before form submissions
        document.addEventListener('submit', function(e) {
            if (!navigator.onLine) {
                e.preventDefault();
                alert('Internet connection required. Please check your connection and try again.');
            }
        });
        
        // Show online status indicator
        function updateOnlineStatus() {
            const statusIndicator = document.getElementById('onlineStatus');
            if (statusIndicator) {
                if (navigator.onLine) {
                    statusIndicator.className = 'badge bg-success';
                    statusIndicator.textContent = 'Online';
                } else {
                    statusIndicator.className = 'badge bg-danger';
                    statusIndicator.textContent = 'Offline';
                }
            }
        }
        
        window.addEventListener('online', updateOnlineStatus);
        window.addEventListener('offline', updateOnlineStatus);
        updateOnlineStatus();
    </script>

    <script src="<?php echo APP_URL; ?>/assets/js/slideshow.js"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/modal-failsafe.js"></script>

<!-- Offline warning banner (network-only app: warns when internet is lost) -->
<div id="offlineBanner">⚠️ An active internet connection is required. You appear to be offline.</div>

<script nonce="<?php echo CSP_NONCE; ?>">
document.addEventListener('DOMContentLoaded', function () {
    var banner = document.getElementById('offlineBanner');
    function updateConnectionStatus() {
        if (!banner) return;
        if (navigator.onLine) {
            banner.classList.remove('show');
        } else {
            banner.classList.add('show');
        }
    }
    window.addEventListener('online', updateConnectionStatus);
    window.addEventListener('offline', updateConnectionStatus);
    updateConnectionStatus();
});
</script>

<script nonce="<?php echo CSP_NONCE; ?>">
document.addEventListener('DOMContentLoaded', function() {
    var logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
            var formData = new FormData();
            formData.append('csrf_token', '<?php echo htmlspecialchars(generateCsrfToken()); ?>');
            fetch('<?php echo APP_URL; ?>/api/auth.php?action=logout', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.redirect) {
                    window.location.href = data.redirect;
                } else {
                    window.location.href = '<?php echo APP_URL; ?>/index.html';
                }
            })
            .catch(function() {
                window.location.href = '<?php echo APP_URL; ?>/index.html';
            });
        });
    }
});
</script>

<!-- Register the network-only service worker (no offline caching) -->
<script nonce="<?php echo CSP_NONCE; ?>">
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?php echo APP_URL; ?>/service-worker.js')
            .catch(function (err) {
                console.warn('Service worker registration failed:', err);
            });
    });
}
</script>

<script nonce="<?php echo CSP_NONCE; ?>">
// Hide PWA splash screen once the app is ready
document.addEventListener('DOMContentLoaded', function () {
    var splash = document.getElementById('appSplash');
    if (splash) {
        splash.classList.add('is-hidden');
        // Mark splash as shown for this session so subsequent navigations skip it.
        try { sessionStorage.setItem('gyfSplashShown', '1'); } catch (e) {}
        // Clean up after transition so it doesn't intercept clicks
        setTimeout(function () { splash.remove(); }, 400);
    }
});

// Global page transition loader
(function () {
    var loader = document.getElementById('pageLoader');
    if (!loader) return;

    function showLoader() {
        loader.classList.add('is-active');
    }
    function hideLoader() {
        loader.classList.remove('is-active');
    }

    // Internal navigation links (same origin, not anchors, not external, not new tab)
    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('a') : null;
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href) return;
        if (link.hasAttribute('download')) return;
        if (link.getAttribute('target') === '_blank') return;
        if (href.indexOf('#') === 0) return;
        if (href.indexOf('javascript:') === 0) return;
        if (href.indexOf('mailto:') === 0) return;
        if (href.indexOf('tel:') === 0) return;
        try {
            var origin = new URL(href, window.location.origin).origin;
            if (origin !== window.location.origin) return;
        } catch (ex) {
            return;
        }
        showLoader();
    });

    // Form submissions
    document.addEventListener('submit', function () {
        showLoader();
    });

    // Always hide loader when the new page is fully loaded
    window.addEventListener('load', function () {
        hideLoader();
    });

    // Fallback: hide after a short timeout in case load never fires
    setTimeout(hideLoader, 4000);
})();
</script>
</body>
</html>

