const serverMetricsModals = new WeakMap();

function formatMetricValue(value, unit) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '—';
    const number = Number(value);
    if (unit === '%') return `${number.toFixed(1)}%`;
    if (unit === 'bytes' || unit === 'kilobytes') {
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        let size = unit === 'kilobytes' ? number * 1024 : number;
        let index = 0;
        while (size >= 1024 && index < units.length - 1) { size /= 1024; index += 1; }
        return `${size.toFixed(index ? 1 : 0)} ${units[index]}`;
    }
    return Math.round(number).toLocaleString();
}

function hasValues(series) {
    return Array.isArray(series.data) && series.data.some((value) => value !== null && value !== undefined);
}

function setupServerMetricsModal(container) {
    if (serverMetricsModals.has(container)) return;
    const initialSeries = JSON.parse(container.dataset.initialSeries || '{}');
    const state = { chart: null, controller: null, series: initialSeries };
    serverMetricsModals.set(container, state);

    const metric = container.querySelector('.js-server-metric');
    const range = container.querySelector('.js-server-range');
    const refresh = container.querySelector('.js-server-metrics-refresh');
    const reload = (force = false) => requestSeries(container, force);
    metric?.addEventListener('change', () => reload());
    range?.addEventListener('change', () => reload());
    refresh?.addEventListener('click', () => reload(true));
    renderSeries(container, initialSeries, { granularity: initialSeries.granularity });
}

function requestSeries(container, force) {
    const state = serverMetricsModals.get(container);
    if (!state) return;
    const controller = new AbortController();
    state.controller?.abort();
    state.controller = controller;
    const isCurrent = () => serverMetricsModals.get(container) === state && state.controller === controller;
    const metric = container.querySelector('.js-server-metric')?.value || 'cpu';
    const rangeElement = container.querySelector('.js-server-range');
    const range = rangeElement?.value || '24h';
    const granularity = rangeElement?.selectedOptions?.[0]?.dataset.granularity || 'auto';
    const params = new URLSearchParams({ range, granularity });
    if (force) params.set('force', '1');
    setLoading(container, true);
    hideError(container);
    const endpoint = new URL(container.dataset.apiEndpoint, window.location.origin);
    endpoint.pathname = endpoint.pathname.replace(/\/[^/]+$/, `/${encodeURIComponent(metric)}`);
    endpoint.search = params.toString();
    fetch(endpoint.toString(), {
        headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal,
    })
        .then((response) => {
            if (!response.ok) throw new Error(`Request failed with status ${response.status}`);
            return response.json();
        })
        .then((payload) => { if (isCurrent()) renderSeries(container, payload.series || {}, payload.filters || {}); })
        .catch((error) => { if (isCurrent() && error.name !== 'AbortError') showError(container, 'Unable to load metrics data.'); })
        .finally(() => { if (isCurrent()) setLoading(container, false); });
}

function renderSeries(container, series, filters = {}) {
    const state = serverMetricsModals.get(container);
    if (!state) return;
    state.series = series;
    const granularity = filters.granularity || series.granularity;
    const meta = container.closest('.server-metrics-modal')?.querySelector('.js-server-metrics-meta');
    if (meta && granularity) meta.textContent = `UTC · ${granularity}`;
    const canvas = container.querySelector('.js-server-metrics-chart');
    const empty = container.querySelector('.js-server-metrics-empty');
    const partial = container.querySelector('.js-server-metrics-partial');
    const values = Array.isArray(series.data) ? series.data.filter((value) => value !== null && value !== undefined) : [];
    if (!canvas) return;
    const present = hasValues(series);
    canvas.hidden = !present;
    empty.hidden = present;
    partial.hidden = !present || values.length === (series.data || []).length;
    if (!present) {
        state.chart?.destroy();
        state.chart = null;
    } else if (window.Chart) {
        state.chart?.destroy();
        state.chart = new window.Chart(canvas.getContext('2d'), {
            type: 'line', data: { labels: series.labels || [], datasets: [{ label: series.metric || 'Metric', data: series.data || [], borderColor: window.mataThemeColor('--chart-line'), backgroundColor: window.mataThemeColor('--chart-fill'), themeColors: { borderColor: '--chart-line', backgroundColor: '--chart-fill' }, fill: true, tension: .3, spanGaps: false }] },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, scales: { x: { ticks: { autoSkip: true, maxTicksLimit: 8 } }, y: { ticks: { callback: (value) => formatMetricValue(value, series.unit) } } }, plugins: { legend: { display: false } } },
        });
    }
    const current = values.length ? values[values.length - 1] : null;
    ['min', 'max', 'avg'].forEach((key) => {
        const element = container.querySelector(`[data-stat="${key}"]`);
        if (element) element.textContent = formatMetricValue(series[key], series.unit);
    });
    const currentElement = container.querySelector('[data-stat="current"]');
    if (currentElement) currentElement.textContent = formatMetricValue(current, series.unit);
}

function setLoading(container, loading) { const element = container.querySelector('.js-server-metrics-loading'); if (element) element.hidden = !loading; }
function hideError(container) { const element = container.querySelector('.js-server-metrics-error'); if (element) element.hidden = true; }
function showError(container, message) { const element = container.querySelector('.js-server-metrics-error'); if (element) { element.textContent = message; element.hidden = false; } }
function destroyServerMetricsModal(container) { const state = serverMetricsModals.get(container); if (!state) return; state.controller?.abort(); state.chart?.destroy(); serverMetricsModals.delete(container); }

document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.js-server-metrics-modal').forEach(setupServerMetricsModal));
document.body.addEventListener('htmx:afterSwap', (event) => { const target = event.target instanceof Element ? event.target : event.detail?.target; target?.querySelectorAll?.('.js-server-metrics-modal').forEach(setupServerMetricsModal); });
document.body.addEventListener('htmx:beforeSwap', (event) => { const target = event.target instanceof Element ? event.target : event.detail?.target; target?.querySelectorAll?.('.js-server-metrics-modal').forEach(destroyServerMetricsModal); });
document.body.addEventListener('closeModal', () => document.querySelectorAll('.js-server-metrics-modal').forEach(destroyServerMetricsModal));
