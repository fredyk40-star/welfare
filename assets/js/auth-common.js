// GYF Welfare - Auth Pages Common Scripts
// Bootstrap CSS fallback + 2FA form handler

(function () {
    'use strict';

    // Bootstrap CSS CDN fallback
    const css = document.querySelector('link[href*="bootstrap.min.css"]');
    if (css && !css.sheet) {
        const fallback = document.createElement('link');
        fallback.rel = 'stylesheet';
        fallback.href = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css';
        document.head.appendChild(fallback);
    }

    // Bootstrap JS recovery: if the local bootstrap.bundle.min.js failed to
    // load/execute (stale or missing asset served as HTML), pull it from the
    // CDN so data-bs-* handlers work.
    if (!(window.bootstrap && typeof bootstrap.Collapse !== 'undefined')) {
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js';
        document.head.appendChild(s);
    }
})();

// 2FA form handler (shared between member and treasurer verify-2fa pages)
(function () {
    'use strict';

    const form = document.getElementById('twoFAForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        const verifyButton = document.getElementById('verifyButton');
        const verifyText = document.getElementById('verifyText');
        const verifySpinner = document.getElementById('verifySpinner');
        const code = document.getElementById('code');
        if (!code) return;

        const codeValue = code.value.trim();

        if (!codeValue || codeValue.length !== 6) {
            e.preventDefault();
            alert('Please enter a valid 6-digit verification code.');
            return false;
        }

        if (!navigator.onLine) {
            e.preventDefault();
            alert('Internet connection required. Please check your connection and try again.');
            return false;
        }

        if (verifyButton) {
            verifyButton.disabled = true;
        }
        if (verifyText) {
            verifyText.classList.add('d-none');
        }
        if (verifySpinner) {
            verifySpinner.classList.remove('d-none');
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        const codeInput = document.getElementById('code');
        if (codeInput) {
            codeInput.focus();
        }
    });
})();
