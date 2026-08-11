// GYF Welfare Management System - Main JavaScript

document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Initialize popovers
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
    
    // Auto-hide alerts after 5 seconds
    const alerts = document.querySelectorAll('.alert:not(.alert-dismissible)');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 5000);
    });
    
    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            // e.preventDefault(); // Let browser show native install banner
            const href = this.getAttribute('href');
            if (!href || href === '#') return;
            const target = document.querySelector(href);
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
    
    // Add animation classes to cards on scroll (with fallback so content is never hidden)
    const cards = document.querySelectorAll('.card, .stat-card');
    
    // Ensure all cards are visible by default (fallback in case JS animations fail)
    cards.forEach(card => {
        card.style.opacity = '1';
        card.style.transform = 'none';
    });
    
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        });
        
        cards.forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(20px)';
            card.style.transition = 'all 0.5s ease';
            observer.observe(card);
        });
    }
});

// Handle app installation
window.addEventListener('appinstalled', () => {
    console.log('GYF Welfare app installed');
    deferredPrompt = null;
});

// --- Modal safety: force-remove lingering backdrops and scroll locks ---
// Prevents the grey overlay / page freeze when a modal is closed.
document.addEventListener('DOMContentLoaded', function () {
    const paymentModalEl = document.getElementById('paymentModal');

    if (paymentModalEl) {
        paymentModalEl.addEventListener('hidden.bs.modal', function () {
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        });
    }

    // Global fallback: clean up any stray backdrops left behind on the page
    document.addEventListener('hidden.bs.modal', function (e) {
        if (!document.querySelector('.modal.show')) {
            document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    });
});

// Robust modal opener: clears stray backdrops before opening a fresh instance
let _paymentModalInstance = null;

function openPaymentModal() {
    const el = document.getElementById('paymentModal');
    if (!el) return;

    // Clean up any stray backdrops before opening
    document.querySelectorAll('.modal-backdrop').forEach(backdrop => backdrop.remove());

    if (!_paymentModalInstance) {
        _paymentModalInstance = new bootstrap.Modal(el, {
            backdrop: true,
            keyboard: true
        });
    }
    _paymentModalInstance.show();
}


// Render QR codes from otpauth URIs
function renderQRCode() {
    document.querySelectorAll('.qrcode-canvas').forEach(function(el) {
        var uri = el.dataset.otpauth;
        if (uri && typeof QRCode !== 'undefined') {
            el.innerHTML = '';
            new QRCode(el, {
                text: uri,
                width: 200,
                height: 200,
                correctLevel: QRCode.CorrectLevel.M
            });
        }
    });
}

document.addEventListener('DOMContentLoaded', function() {
    renderQRCode();
});
// Toast notification helper
function showToast(message, type) {
    const container = document.getElementById('toastContainer');
    if (!container) { alert(message); return; }
    const toast = document.createElement('div');
    const bgClass = type === 'success' ? 'bg-success' : (type === 'danger' ? 'bg-danger' : (type === 'warning' ? 'bg-warning' : 'bg-info'));
    toast.className = 'toast align-items-center text-white ' + bgClass + ' border-0';
    toast.setAttribute('role', 'alert');
    toast.innerHTML = '<div class="d-flex"><div class="toast-body">' + message + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast, { delay: 3000 });
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', function() { toast.remove(); });
}

// Event delegation for dynamically generated print buttons
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.js-print-receipt');
    if (btn) {
        e.preventDefault();
        window.print();
    }
});