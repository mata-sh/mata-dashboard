// Tooltips

function tooltipElements(context, selector) {
    const elements = [];

    if (context instanceof Element && context.matches(selector)) {
        elements.push(context);
    }

    elements.push(...context.querySelectorAll(selector));
    return elements.filter((element) => !element._tippy);
}

// Tooltips with long lines grow with their content up to the wide limit.
const wideTooltipSelector = '.app-status, .app-filesize, .fleet-cron';

function initializeTooltips(context = document) {
    const standardTooltips = tooltipElements(
        context,
        `[data-tippy-content]:not(${wideTooltipSelector})`
    );
    const wideTooltips = tooltipElements(
        context,
        `:is(${wideTooltipSelector})[data-tippy-content]`
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

// Modal routes answer failures with an error modal; htmx skips 4xx/5xx swaps by default.
document.body.addEventListener('htmx:beforeSwap', (event) => {
    const { xhr, target } = event.detail;
    const isHtml = xhr.getResponseHeader('Content-Type')?.startsWith('text/html');
    if (xhr.status >= 400 && isHtml && target instanceof Element && target.id === 'modal-container') {
        event.detail.shouldSwap = true;
        event.detail.isError = false;
    }
});

// Other modal failures answer with generic JSON; show its message instead of opening nothing.
// Background polling (bell, audits) is excluded so an outage does not pop up repeatedly.
document.body.addEventListener('htmx:responseError', (event) => {
    const { xhr, elt, target } = event.detail;
    if (!(target instanceof Element) || target.id !== 'modal-container') {
        return;
    }

    let message = `Request failed (HTTP ${xhr.status})`;
    try {
        const body = JSON.parse(xhr.responseText);
        if (typeof body?.message === 'string' && body.message !== '') {
            message = body.message;
        }
    } catch {
        // Not JSON; keep the status message.
    }
    window.mataOpenMessageModal?.(message, elt, 'Request failed');
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

// Fleet summary bar

// The pressed item is the filter, so a full re-render resets it without extra state.
// A filter lists one or more "scope:state" pairs, e.g. "servers:unreachable apps:errors".
const fleetSelectors = {
    servers: (state) => `.server-section .server-container[data-summary${state ? `="${state}"` : ''}]`,
    apps: (state) => `.server-section .server-container .app-container[data-summary${state ? `="${state}"` : ''}]`,
};

function parseFleetFilter(filter) {
    const states = { servers: [], apps: [] };
    (filter ?? '').split(' ').filter(Boolean).forEach((pair) => {
        const [scope, state] = pair.split(':');
        states[scope]?.push(state);
    });
    return states;
}

// State filter and search must both match. A matching server shows all its rows;
// otherwise only the matching rows remain.
function applyFleetFilter(summary) {
    const active = summary.querySelector('.fleet-item[aria-pressed="true"]');
    const states = parseFleetFilter(active?.dataset.fleetFilter);
    const query = (summary.querySelector('#fleet-search')?.value ?? '').trim().toLowerCase();
    const matchesQuery = (element) => (element?.textContent ?? '').toLowerCase().includes(query);
    let anyVisible = false;

    document.querySelectorAll('.server-section .server-container').forEach((card) => {
        const serverState = !active || states.servers.includes(card.dataset.summary);
        const serverText = !query || matchesQuery(card.querySelector('.server-title-text'));
        let rowVisible = false;

        card.querySelectorAll('.app-container').forEach((row) => {
            const visible = (serverState || states.apps.includes(row.dataset.summary))
                && (serverText || matchesQuery(row.querySelector('.app-name')));
            rowVisible ||= visible;
            row.classList.toggle('is-hidden', !visible);
        });

        const cardVisible = rowVisible || (serverState && serverText);
        anyVisible ||= cardVisible;
        card.classList.toggle('is-hidden', !cardVisible);
    });

    const empty = document.querySelector('.fleet-empty');
    if (empty) empty.hidden = anyVisible;
}

// Recount from the cards' data-summary attributes, e.g. after a single card refresh.
function recountFleetSummary(summary) {
    summary.querySelectorAll('.fleet-item').forEach((item) => {
        const states = parseFleetFilter(item.dataset.fleetFilter);
        const count = Object.entries(states).reduce((sum, [scope, list]) => sum + list.reduce(
            (scopeSum, state) => scopeSum + document.querySelectorAll(fleetSelectors[scope](state)).length,
            0
        ), 0);

        item.querySelector('.fleet-count').textContent = count;
        const mini = item.dataset.level && summary.querySelector(`[data-fleet-mini="${item.dataset.level}"]`);
        if (mini) {
            mini.textContent = count;
            mini.parentElement.hidden = count === 0;
        }
        // A pressed item stays enabled so it can still be released.
        item.disabled = count === 0 && item.getAttribute('aria-pressed') !== 'true';
    });

    summary.querySelectorAll('[data-fleet-total]').forEach((total) => {
        total.textContent = document.querySelectorAll(fleetSelectors[total.dataset.fleetTotal]()).length;
    });

    const attention = summary.querySelector('[data-fleet-attention]');
    if (attention) {
        // Items are ordered by severity, so the first non-zero one sets the level.
        const worst = [...attention.querySelectorAll('.fleet-item')]
            .find((item) => item.querySelector('.fleet-count').textContent !== '0');
        attention.dataset.fleetAttention = worst?.dataset.level ?? 'clear';
    }
}

document.body.addEventListener('click', (event) => {
    const item = event.target instanceof Element ? event.target.closest('.fleet-item') : null;
    if (!item) return;

    const summary = item.closest('.fleet-summary');
    const pressed = item.getAttribute('aria-pressed') === 'true';
    summary.querySelectorAll('.fleet-item').forEach((other) => other.setAttribute('aria-pressed', 'false'));
    item.setAttribute('aria-pressed', String(!pressed));

    recountFleetSummary(summary);
    applyFleetFilter(summary);
});

function formatFleetAge(seconds) {
    if (seconds < 60) return 'just now';
    const minutes = Math.floor(seconds / 60);
    return minutes < 60 ? `${minutes} min ago` : `${Math.floor(minutes / 60)} h ago`;
}

// Cards carry their data age at render time; add the time since the browser first saw them.
function updateFleetFreshness(summary) {
    const freshness = summary.querySelector('.fleet-freshness');
    if (!freshness) return;

    const now = Date.now();
    const cards = [...document.querySelectorAll('.server-section .server-container[data-age]')];
    freshness.hidden = cards.length === 0;
    if (cards.length === 0) return;

    let oldest = 0;
    let stale = 0;
    cards.forEach((card) => {
        card.dataset.seenAt ??= String(now);
        const elapsed = (now - Number(card.dataset.seenAt)) / 1000;
        oldest = Math.max(oldest, Number(card.dataset.age) + elapsed);
        if (card.dataset.expiresIn !== undefined && Number(card.dataset.expiresIn) - elapsed < 0) stale++;
    });

    freshness.textContent = `Updated ${formatFleetAge(oldest)}`;
    freshness.classList.toggle('is-stale', stale > 0);

    const tooltip = stale > 0
        ? `${stale} of ${cards.length} servers are older than their refresh interval`
        : 'Age of the oldest server data on this page';
    freshness.dataset.tippyContent = tooltip;
    freshness._tippy?.setContent(tooltip);
}

// Auto-refresh reloads the card grid from the cache-backed servers route; the summary,
// its search text and filter stay in place and recount from the new cards.
const fleetAutoRefreshKey = 'mata-fleet-auto-refresh';
const fleetAutoRefreshMs = 60000;
let fleetLastRefresh = Date.now();
let fleetRefreshing = false;

function setFleetAutoRefresh(summary, enabled) {
    const button = summary.querySelector('.fleet-autorefresh');
    if (!button) return;

    button.setAttribute('aria-pressed', String(enabled));
    const tooltip = enabled ? 'Auto-refresh on: cards reload every minute' : 'Auto-refresh off';
    button.dataset.tippyContent = tooltip;
    button._tippy?.setContent(tooltip);
}

async function refreshFleetCards(summary) {
    const url = document.getElementById('main-section')?.getAttribute('hx-get');
    const section = document.querySelector('.server-section');
    if (!url || !section || fleetRefreshing) return;

    fleetRefreshing = true;
    const button = summary.querySelector('.fleet-autorefresh');
    button?.classList.add('is-refreshing');
    try {
        const response = await fetch(url, { headers: { 'HX-Request': 'true' } });
        if (!response.ok) return;

        const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
        const freshSection = fresh.querySelector('.server-section');
        // An expired session answers with the login page; keep the current cards.
        if (!freshSection) return;

        tippy.hideAll({ duration: 0 });
        section.replaceWith(freshSection);
        htmx.process(freshSection);
        initializeTooltips(freshSection);

        const cron = summary.querySelector('.fleet-cron');
        const freshCron = fresh.querySelector('.fleet-cron');
        if (cron && freshCron) {
            cron.replaceWith(freshCron);
            initializeTooltips(freshCron);
        }

        recountFleetSummary(summary);
        applyFleetFilter(summary);
        updateFleetFreshness(summary);
    } catch {
        // Network hiccup; the next tick tries again.
    } finally {
        fleetLastRefresh = Date.now();
        fleetRefreshing = false;
        button?.classList.remove('is-refreshing');
    }
}

// Wait while the viewer is busy: an open modal or tooltip, a running card request, or
// keyboard focus inside the grid. Replacing the cards would lose focus or the request.
function fleetRefreshBlocked() {
    return document.hidden
        || document.querySelector('#modal, #message-modal.is-active, [data-tippy-root], .server-section .htmx-request') !== null
        || document.activeElement?.closest('.server-section') != null;
}

// A skipped refresh catches up within one tick once the viewer is done.
setInterval(() => {
    const summary = document.getElementById('fleet-summary');
    if (!summary) return;

    updateFleetFreshness(summary);
    const enabled = summary.querySelector('.fleet-autorefresh')?.getAttribute('aria-pressed') === 'true';
    if (enabled && Date.now() - fleetLastRefresh >= fleetAutoRefreshMs && !fleetRefreshBlocked()) {
        refreshFleetCards(summary);
    }
}, 15000);

document.body.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('.fleet-autorefresh') : null;
    if (!button) return;

    const enabled = button.getAttribute('aria-pressed') !== 'true';
    setFleetAutoRefresh(button.closest('.fleet-summary'), enabled);
    try {
        localStorage.setItem(fleetAutoRefreshKey, enabled ? '1' : '0');
    } catch {
        // Storage unavailable; the choice lasts until the next page load.
    }
});

const fleetCollapsedKey = 'mata-fleet-summary-collapsed';

function setFleetCollapsed(summary, collapsed) {
    summary.classList.toggle('is-collapsed', collapsed);
    summary.querySelectorAll('.fleet-expand, .fleet-collapse')
        .forEach((button) => button.setAttribute('aria-expanded', String(!collapsed)));

    // A hidden state filter would hide cards without saying why.
    if (collapsed) {
        summary.querySelectorAll('.fleet-item').forEach((item) => item.setAttribute('aria-pressed', 'false'));
    }
}

document.body.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('.fleet-expand, .fleet-collapse') : null;
    if (!button) return;

    const summary = button.closest('.fleet-summary');
    const collapsed = button.classList.contains('fleet-collapse');
    setFleetCollapsed(summary, collapsed);
    try {
        localStorage.setItem(fleetCollapsedKey, collapsed ? '1' : '0');
    } catch {
        // Storage unavailable; the choice lasts until the next page load.
    }

    recountFleetSummary(summary);
    applyFleetFilter(summary);
});

document.body.addEventListener('input', (event) => {
    if (!(event.target instanceof Element) || event.target.id !== 'fleet-search') return;

    applyFleetFilter(event.target.closest('.fleet-summary'));
});

// Restore the collapsed state before the first paint of a freshly swapped summary.
document.body.addEventListener('htmx:afterSwap', () => {
    const summary = document.getElementById('fleet-summary');
    if (!summary || summary.dataset.restored) return;

    summary.dataset.restored = 'true';
    // Compact unless the viewer opened the full summary before.
    let collapsed = true;
    try {
        collapsed = localStorage.getItem(fleetCollapsedKey) !== '0';
    } catch {
        // Storage unavailable; stay compact.
    }
    setFleetCollapsed(summary, collapsed);

    let autoRefresh = true;
    try {
        autoRefresh = localStorage.getItem(fleetAutoRefreshKey) !== '0';
    } catch {
        // Storage unavailable; keep auto-refresh on.
    }
    setFleetAutoRefresh(summary, autoRefresh);
    fleetLastRefresh = Date.now();
});

document.body.addEventListener('htmx:afterSettle', () => {
    const summary = document.getElementById('fleet-summary');
    if (!summary) return;

    recountFleetSummary(summary);
    applyFleetFilter(summary);
    updateFleetFreshness(summary);
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
