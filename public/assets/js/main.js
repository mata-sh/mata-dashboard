// Tooltips

function tooltipElements(context, selector) {
    const elements = [];

    if (context instanceof Element && context.matches(selector)) {
        elements.push(context);
    }

    elements.push(...context.querySelectorAll(selector));
    return elements.filter((element) => !element._tippy);
}

function initializeTooltips(context = document) {
    const standardTooltips = tooltipElements(
        context,
        '[data-tippy-content]:not(.app-status):not(.app-filesize)'
    );
    const wideTooltips = tooltipElements(
        context,
        '.app-status[data-tippy-content], .app-filesize[data-tippy-content]'
    );

    if (standardTooltips.length > 0) {
        tippy(standardTooltips, { allowHTML: true });
    }

    if (wideTooltips.length > 0) {
        tippy(wideTooltips, {
            allowHTML: true,
            maxWidth: 560,
        });
    }
}

initializeTooltips();

// Progress bar

const progressBar = document.getElementById('htmx-progress-bar');
const progressTransition = 'width 0.1s linear, opacity 0.3s ease';
const progressFadeOutMs = 300;
const activeRequests = new Set();

let progressValue = 0;
let progressTimer = null;
let progressResetTimer = null;

function setProgressWidth(value) {
    progressValue = value;
    progressBar.style.width = value + '%';
}

function startProgress(request) {
    activeRequests.add(request);

    if (!progressBar) return;

    if (progressResetTimer !== null) {
        clearTimeout(progressResetTimer);
        progressResetTimer = null;
    }

    if (activeRequests.size > 1) return;

    clearInterval(progressTimer);
    progressBar.style.transition = 'none';
    setProgressWidth(10);
    progressBar.style.opacity = '1';
    void progressBar.offsetHeight;
    progressBar.style.transition = progressTransition;

    progressTimer = setInterval(() => {
        if (progressValue < 90) {
            setProgressWidth(progressValue + 1);
        }
    }, 500);
}

function finishProgress(request) {
    const requestFinished = activeRequests.delete(request);
    if (!requestFinished || activeRequests.size > 0 || !progressBar) return;

    clearInterval(progressTimer);
    progressTimer = null;
    setProgressWidth(100);
    progressBar.style.opacity = '0';

    progressResetTimer = setTimeout(() => {
        progressResetTimer = null;
        if (activeRequests.size > 0) return;

        // Jump to 0% without animation, then restore the transition.
        progressBar.style.transition = 'none';
        setProgressWidth(0);
        void progressBar.offsetHeight;
        progressBar.style.transition = progressTransition;
    }, progressFadeOutMs);
}

// HTMX event handling

// Hide loading indicator before HTMX displays the container.
document.body.addEventListener('htmx:beforeSwap', () => {
    document.getElementById('loading-indicator')?.style.setProperty('visibility', 'hidden');
});

document.body.addEventListener('htmx:configRequest', (event) => {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        event.detail.headers['X-CSRF-Token'] = csrfMeta.content;
    }
});

document.body.addEventListener('htmx:beforeSend', (event) => {
    startProgress(event.detail.xhr);
});

document.body.addEventListener('htmx:afterSwap', (event) => {
    if (event.target instanceof Element) {
        initializeTooltips(event.target);
        loadMetricsDashboard(event.target);
    }
});

document.body.addEventListener('htmx:afterRequest', (event) => {
    finishProgress(event.detail.xhr);

    // Close the notification that triggered this request once the choice is saved.
    const trigger = event.detail?.elt;
    if (event.detail?.successful && trigger?.matches?.('[data-notification-action="close"]')) {
        trigger.closest('.notification')?.classList.add('is-hidden');
    }
});

// Dynamic module loading: metrics dashboard

let metricsDashboardLoaded = false;
let metricsDashboardLoading = false;

function loadMetricsDashboard(context = document) {
    const hasDashboard = (context instanceof Element && context.matches('.js-metrics-dashboard'))
        || context.querySelector('.js-metrics-dashboard');
    if (!hasDashboard || metricsDashboardLoaded || metricsDashboardLoading) return;

    metricsDashboardLoading = true;

    const script = document.createElement('script');
    script.src = '/assets/js/metrics-dashboard.js';
    script.async = true;

    script.onload = () => {
        metricsDashboardLoaded = true;
        metricsDashboardLoading = false;
        console.log('[main] Metrics dashboard module loaded');
    };

    script.onerror = () => {
        metricsDashboardLoading = false;
        console.error('[main] Failed to load metrics dashboard module');
    };

    document.head.appendChild(script);
}

document.addEventListener('DOMContentLoaded', () => loadMetricsDashboard(document));

// Navigation burger

const burger = document.querySelector('.navbar-burger');
if (burger) {
    const menu = document.getElementById(burger.dataset.target);
    burger.addEventListener('click', () => {
        burger.classList.toggle('is-active');
        menu.classList.toggle('is-active');
    });
}

// Modals and notifications

// Close modal on Escape key press.
document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape' && event.keyCode !== 27) return;

    document.querySelector('#modal')?.dispatchEvent(new CustomEvent('closeModal'));
}, true);

// Close button inside a rendered notification.
document.body.addEventListener('click', (event) => {
    const button = event.target instanceof Element
        ? event.target.closest('[data-notification-action="close"]')
        : null;
    // HTMX-backed buttons close in htmx:afterRequest, after the server saved the choice.
    if (!button || button.hasAttribute('hx-post')) return;

    button.closest('.notification')?.classList.add('is-hidden');
});

// Dropdown population (called from the *-table.js modules)

function mataPopulateSelectFromRows(selectId, rows, field, placeholder) {
    const select = document.getElementById(selectId);
    if (!select || !Array.isArray(rows)) return;

    const cacheKey = `mata-select:${selectId}`;
    const currentValue = select.value;
    const cachedValue = sessionStorage?.getItem(cacheKey) ?? null;

    const valueSet = new Set();
    for (const row of rows) {
        const rawValue = row?.[field];
        if (typeof rawValue !== 'string') continue;

        const value = rawValue.trim();
        if (value) {
            valueSet.add(value);
        }
    }

    const values = [...valueSet]
        .sort((a, b) => a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' }));

    select.innerHTML = '';

    for (const value of ['', ...values]) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value || placeholder || 'All';
        select.appendChild(option);
    }

    const preferredValue = currentValue || cachedValue;
    if (preferredValue && values.includes(preferredValue)) {
        select.value = preferredValue;
    }

    sessionStorage?.setItem(cacheKey, select.value);

    if (!select.dataset.mataCacheBound) {
        select.addEventListener('change', () => sessionStorage?.setItem(cacheKey, select.value));
        select.dataset.mataCacheBound = 'true';
    }
}

window.mataPopulateSelectFromRows = mataPopulateSelectFromRows;

// Tabulator cell formatters (called from the *-table.js modules)

const mataLevelColors = Object.freeze({
    critical: '#ff4d4d',
    error: '#ff8c42',
    warning: '#ffd24d',
    info: '#3FB449',
});

function mataFormatLevel(cell) {
    const value = cell.getValue();

    if (!Object.prototype.hasOwnProperty.call(mataLevelColors, value)) {
        return value;
    }

    return `<span style="color:${mataLevelColors[value]}; font-weight:bold;">${value}</span>`;
}

window.mataFormatLevel = mataFormatLevel;
