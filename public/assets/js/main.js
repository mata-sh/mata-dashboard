
tippy('[data-tippy-content]', {
    allowHTML: true,
});

// Hide Lodading Indicator before Htmx displays Container
document.body.addEventListener('htmx:beforeSwap', function (evt) {
    document.getElementById('loading-indicator')?.style.setProperty('visibility', 'hidden');
});


// Progress bar
const bar = document.getElementById('htmx-progress-bar');
let progressInterval;
let current = 0;

document.body.addEventListener('htmx:configRequest', function () {
    if (bar) {
        current = 10;
        bar.style.width = current + '%';

        progressInterval = setInterval(() => {
            if (current < 90) {
                current += 1;
                bar.style.width = current + '%';
            }
        }, 500);
        bar.style.opacity = '1';
    }
});

document.body.addEventListener('htmx:beforeOnLoad', function () {
    if (bar) {
        clearInterval(progressInterval);
        bar.style.width = '100%';
    }
});

document.body.addEventListener('htmx:afterSwap', function () {
    if (bar) {
        bar.style.opacity = '0';
        setTimeout(() => {
            bar.style.transition = 'none';
            bar.style.width = '0%';
            bar.offsetHeight;
            bar.style.transition = 'width 0.1s linear, opacity 0.3s ease';
        }, 300); // fade-out duration
    }
});

document.body.addEventListener('htmx:afterRequest', function () {
    if (bar) {
        clearInterval(progressInterval);
        bar.style.transition = 'width 0.1s linear, opacity 0.3s ease';
        tippy('[data-tippy-content]', {
            allowHTML: true,
        });
    }
});

const burger = document.querySelector('.navbar-burger');
const menu = document.getElementById(burger.dataset.target);

burger.addEventListener('click', () => {
    burger.classList.toggle('is-active');
    menu.classList.toggle('is-active');
});

// Close modal on Escape key press
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' || event.keyCode === 27) {
        const modal = document.querySelector('#modal');
        if (modal) {
            // Trigger the closeModal event using HTMX
            htmx.trigger(modal, 'closeModal');
        }
    }
});

// ────────────────────────────────────────────────
// Dynamic Module Loading - Metrics Dashboard
// ────────────────────────────────────────────────
// Loads metrics-dashboard.js only when needed (when .js-metrics-dashboard exists)

let metricsDashboardScriptLoaded = false;
let metricsDashboardScriptLoading = false;

function loadMetricsDashboard(context) {
    // Check if metrics dashboard elements exist
    const hasDashboard = (context || document).querySelector('.js-metrics-dashboard');

    if (!hasDashboard) {
        return; // No metrics dashboard on page
    }

    // Already loaded or loading
    if (metricsDashboardScriptLoaded || metricsDashboardScriptLoading) {
        return;
    }

    metricsDashboardScriptLoading = true;

    const script = document.createElement('script');
    script.src = '/assets/js/metrics-dashboard.js';
    script.async = true;

    script.onload = () => {
        metricsDashboardScriptLoaded = true;
        metricsDashboardScriptLoading = false;
        console.log('[main] Metrics dashboard module loaded');
    };

    script.onerror = () => {
        metricsDashboardScriptLoading = false;
        console.error('[main] Failed to load metrics dashboard module');
    };

    document.head.appendChild(script);
}

// Check on page load
document.addEventListener('DOMContentLoaded', () => {
    loadMetricsDashboard(document);
});

// Check after HTMX content swaps
document.body.addEventListener('htmx:afterSwap', (event) => {
    if (event.target instanceof Element) {
        loadMetricsDashboard(event.target);
    }
});
