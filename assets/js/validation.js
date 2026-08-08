// GYF Welfare Management System - Client-side Validation

document.addEventListener('DOMContentLoaded', function() {
    // Password strength validation
    const passwordFields = document.querySelectorAll('input[type="password"]');
    passwordFields.forEach(field => {
        field.addEventListener('input', function() {
            validatePasswordStrength(this);
        });
    });
    
    // Form validation
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!validateForm(this)) {
                event.preventDefault();
                event.stopPropagation();
            }
        });
    });
    
    // Phone number formatting
    const phoneFields = document.querySelectorAll('input[type="tel"]');
    phoneFields.forEach(field => {
        field.addEventListener('input', function() {
            formatPhoneNumber(this);
        });
    });
});

function validatePasswordStrength(field) {
    const password = field.value;
    const feedback = field.nextElementSibling;
    
    if (password.length === 0) {
        field.classList.remove('is-valid', 'is-invalid');
        return;
    }
    
    const hasUppercase = /[A-Z]/.test(password);
    const hasLowercase = /[a-z]/.test(password);
    const hasNumber = /[0-9]/.test(password);
    const hasSpecial = /[!@#$%^&*()\-_=+{};:,<.>]/.test(password);
    const isValidLength = password.length >= 8 && password.length <= 12;
    
    const isValid = hasUppercase && hasLowercase && hasNumber && hasSpecial && isValidLength;
    
    if (isValid) {
        field.classList.add('is-valid');
        field.classList.remove('is-invalid');
    } else {
        field.classList.add('is-invalid');
        field.classList.remove('is-valid');
        
        if (feedback && feedback.classList.contains('text-muted')) {
            let message = 'Password must contain: ';
            if (!isValidLength) message += '8-12 characters, ';
            if (!hasUppercase) message += 'uppercase letter, ';
            if (!hasLowercase) message += 'lowercase letter, ';
            if (!hasNumber) message += 'number, ';
            if (!hasSpecial) message += 'special character, ';
            feedback.textContent = message.slice(0, -2);
        }
    }
}

function validateForm(form) {
    let isValid = true;
    const requiredFields = form.querySelectorAll('[required]');
    
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    // Password confirmation validation
    const password = form.querySelector('#password');
    const confirmPassword = form.querySelector('#confirm_password');
    
    if (password && confirmPassword) {
        if (password.value !== confirmPassword.value) {
            confirmPassword.classList.add('is-invalid');
            isValid = false;
        }
    }
    
    // Email validation
    const emailFields = form.querySelectorAll('input[type="email"]');
    emailFields.forEach(field => {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (field.value && !emailRegex.test(field.value)) {
            field.classList.add('is-invalid');
            isValid = false;
        }
    });
    
    if (!isValid) {
        showAlert('Please correct the errors in the form.', 'danger');
    }
    
    return isValid;
}

function formatPhoneNumber(field) {
    let value = field.value.replace(/\D/g, '');
    
    if (value.length > 0) {
        if (value.startsWith('0')) {
            value = '+233' + value.substring(1);
        } else if (!value.startsWith('233') && value.length === 9) {
            value = '+233' + value;
        } else if (value.startsWith('233')) {
            value = '+' + value;
        }
        
        // Format: +233 XX XXX XXXX
        if (value.length > 3) value = value.substring(0, 3) + ' ' + value.substring(3);
        if (value.length > 6) value = value.substring(0, 6) + ' ' + value.substring(6);
        if (value.length > 10) value = value.substring(0, 10) + ' ' + value.substring(10);
    }
    
    field.value = value;
}

function showAlert(message, type = 'info') {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.querySelector('.container');
    if (container) {
        container.insertBefore(alertDiv, container.firstChild);
        
        setTimeout(() => {
            alertDiv.remove();
        }, 5000);
    }
}

// Online status checker
function checkOnlineStatus() {
    if (!navigator.onLine) {
        showAlert('Internet connection required. Please check your connection and try again.', 'danger');
        return false;
    }
    return true;
}

// Add online/offline event listeners
window.addEventListener('online', () => {
    showAlert('Internet connection restored!', 'success');
});

window.addEventListener('offline', () => {
    showAlert('Internet connection lost. Some features may not work.', 'warning');
});