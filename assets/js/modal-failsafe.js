// GYF Welfare - Modal Failsafe
// Force-clean stuck backdrops / modal-open / overflow lock

(function () {
    'use strict';

    function cleanupModalBackdrops() {
        document.querySelectorAll('.modal-backdrop').forEach(function (b) { b.remove(); });
        document.body.classList.remove('modal-open');
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }

    // Catch ALL hidden.bs.modal events via event delegation (covers dynamically created modals too)
    document.addEventListener('hidden.bs.modal', function (e) {
        if (!document.querySelector('.modal.show')) {
            cleanupModalBackdrops();
        }
    });

    // Backdrop-click / ESC fallback: Bootstrap should fire hidden.bs.modal, but if it doesn't,
    // clean up after a short delay so the page never stays locked.
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('modal-backdrop')) {
            setTimeout(cleanupModalBackdrops, 50);
        }
    });

    // Keyboard fallback: ESC should close any open modal and clean up.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const openModal = document.querySelector('.modal.show');
            if (openModal) {
                const modalInstance = bootstrap.Modal.getInstance(openModal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
        }
    });
})();
