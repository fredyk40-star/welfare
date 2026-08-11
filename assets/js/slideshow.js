// GYF Welfare - Background Slideshow
// Usage: include this on pages with a <div id="bgSlideshow"></div> container

(function () {
    'use strict';

    const container = document.getElementById('bgSlideshow');
    if (!container) return;

    const base = (function () {
        const scripts = document.currentScript ? [document.currentScript] : document.getElementsByTagName('script');
        const thisScript = scripts.length === 1 ? scripts[0] : scripts[scripts.length - 1];
        const src = thisScript.src;
        if (src && src.indexOf('/assets/js/slideshow.js') !== -1) {
            return src.substring(0, src.lastIndexOf('/assets/js/slideshow.js')) + '/';
        }
        return window.location.origin + '/';
    })();
    const images = [];

    images.push(base + 'assets/images/glassmorphism-background.jpg');

    const slides = [];
    let current = 0;
    const interval = 5000;
    let timer = null;

    function createSlide(src, idx) {
        const div = document.createElement('div');
        div.className = 'slide' + (idx === 0 ? ' active' : '');
        div.style.backgroundImage = 'url(' + src + ')';
        container.appendChild(div);
        slides.push(div);
    }

    function preloadImage(src) {
        return new Promise(function (resolve) {
            const img = new Image();
            img.onload = function () { resolve(true); };
            img.onerror = function () { resolve(false); };
            img.src = src;
        });
    }

    async function loadSlides() {
        for (let i = 0; i < images.length; i++) {
            const ok = await preloadImage(images[i]);
            if (ok) {
                createSlide(images[i], slides.length);
            }
        }
        if (slides.length === 0) { container.style.display = "none"; return; }
        startAutoplay();
    }

    function startAutoplay() {
        stopAutoplay();
        timer = setInterval(next, interval);
    }

    function stopAutoplay() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function next() {
        if (slides.length === 0) { container.style.display = "none"; return; }
        slides[current].classList.remove('active');
        current = (current + 1) % slides.length;
        slides[current].classList.add('active');
    }

    function prev() {
        if (slides.length === 0) { container.style.display = "none"; return; }
        slides[current].classList.remove('active');
        current = (current - 1 + slides.length) % slides.length;
        slides[current].classList.add('active');
    }

    let touchStartX = 0;
    let touchEndX = 0;
    const swipeThreshold = 50;

    container.addEventListener('touchstart', function (e) {
        touchStartX = e.changedTouches[0].screenX;
        stopAutoplay();
    }, { passive: true });

    container.addEventListener('touchend', function (e) {
        touchEndX = e.changedTouches[0].screenX;
        const diff = touchStartX - touchEndX;
        if (Math.abs(diff) > swipeThreshold) {
            if (diff > 0) {
                next();
            } else {
                prev();
            }
        }
        startAutoplay();
    }, { passive: true });

    loadSlides();
})();
