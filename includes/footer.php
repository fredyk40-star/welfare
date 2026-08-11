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
</script></body>
</html>

