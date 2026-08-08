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
                            Logged in as: <strong style="color: var(--text-secondary);"><?php echo $_SESSION['user_id']; ?></strong>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Scripts (version-busted so stale cached Bootstrap can never execute) -->
    <script src="<?php echo APP_URL; ?>/assets/bootstrap/js/bootstrap.bundle.min.js?v=20260807"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/main.js?v=20260807"></script>
    <script src="<?php echo APP_URL; ?>/assets/js/validation.js?v=20260807"></script>
    
    <!-- Online Status Check -->
    <script>
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

    <!-- Modal failsafe: force-clean stuck backdrops / modal-open / overflow lock -->
    <script>
    (function() {
        function cleanupModalBackdrops() {
            document.querySelectorAll('.modal-backdrop').forEach(function(b) { b.remove(); });
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }

        // Catch ALL hidden.bs.modal events via event delegation (covers dynamically created modals too)
        document.addEventListener('hidden.bs.modal', function(e) {
            if (!document.querySelector('.modal.show')) {
                cleanupModalBackdrops();
            }
        });

        // Backdrop-click / ESC fallback: Bootstrap should fire hidden.bs.modal, but if it doesn't,
        // catch the click on the backdrop itself and clean up after a short delay
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal-backdrop')) {
                setTimeout(cleanupModalBackdrops, 50);
            }
        });

        // Final safety net on page load: clean up any stuck backdrops from a previous interrupted state
        window.addEventListener('load', function() {
            setTimeout(cleanupModalBackdrops, 100);
        });
    })();
    </script>

</body>
</html>

