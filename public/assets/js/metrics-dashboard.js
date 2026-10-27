// ────────────────────────────────────────────────
// Metrics Dashboard Module
// ────────────────────────────────────────────────
// Handles Chart.js-based metrics visualization for server monitoring
// Features: interactive charts, server filtering, time range selection

const metricsDashboards = new WeakMap();

function initializeMetricsDashboards(context) {
    const scope = context || document;
    if (typeof window.Chart === 'undefined') {
        console.warn('[metrics-dashboard] Chart.js not available; skipping initialization.');
        return;
    }

    scope.querySelectorAll('.js-metrics-dashboard').forEach((container) => {
        if (!metricsDashboards.has(container)) {
            setupMetricsDashboard(container);
        }
    });
}

function setupMetricsDashboard(container) {
    const servers = parseServers(container.dataset.servers);
    const defaultMetric = container.dataset.defaultMetric || 'cpu';
    const defaultTimespan = container.dataset.defaultTimespan || '24h';

    const state = {
        chart: null,
        servers,
        defaultMetric,
        defaultTimespan,
        activeMetric: defaultMetric,
        activeTimespan: defaultTimespan,
        unit: '%',
    };

    metricsDashboards.set(container, state);

    container.addEventListener('click', (event) => {
        const target = event.target instanceof Element
            ? event.target.closest('[data-metric], [data-timespan], .js-metrics-refresh, .js-metrics-reset')
            : null;

        if (!target) {
            return;
        }

        event.preventDefault();

        if (target.matches('[data-metric]')) {
            const metric = target.getAttribute('data-metric') || state.defaultMetric;
            if (metric !== state.activeMetric) {
                requestDashboardData(container, { metric, timespan: state.activeTimespan });
            }
            return;
        }

        if (target.matches('[data-timespan]')) {
            const timespan = target.getAttribute('data-timespan') || state.defaultTimespan;
            if (timespan !== state.activeTimespan) {
                requestDashboardData(container, { metric: state.activeMetric, timespan });
            }
            return;
        }

        if (target.classList.contains('js-metrics-refresh')) {
            requestDashboardData(container, {
                metric: state.activeMetric,
                timespan: state.activeTimespan,
                force: true,
            });
            return;
        }

        if (target.classList.contains('js-metrics-reset')) {
            requestDashboardData(container, {
                metric: state.defaultMetric,
                timespan: state.defaultTimespan,
            });
        }
    });

    requestDashboardData(container, { metric: defaultMetric, timespan: defaultTimespan });
}

function requestDashboardData(container, { metric, timespan, force = false }) {
    const state = metricsDashboards.get(container);
    if (!state) {
        return;
    }

    const endpoint = container.dataset.chartEndpoint || '/api/metrics/dashboard';
    const params = new URLSearchParams();

    params.set('metric', metric);
    params.set('timespan', timespan);

    if (Array.isArray(state.servers) && state.servers.length > 0) {
        const serversParam = state.servers
            .map((server) => (typeof server === 'string' ? server : server.name))
            .filter(Boolean)
            .join(',');
        if (serversParam) {
            params.set('servers', serversParam);
        }
    }

    if (force) {
        params.set('force', '1');
    }

    toggleMetricsLoading(container, true);
    hideMetricsError(container);

    fetch(`${endpoint}?${params.toString()}`, {
        headers: {
            Accept: 'application/json',
        },
    })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }
            return response.json();
        })
        .then((data) => {
            state.activeMetric = metric;
            state.activeTimespan = timespan;
            updateMetricButtons(container, metric);
            updateTimespanButtons(container, timespan);
            renderMetricsChart(container, data);
            renderMetricsStats(container, data);
            renderCacheMetadata(container, data.cache);
            toggleEmptyState(container, Array.isArray(data.labels) && data.labels.length === 0);
            if (Array.isArray(data.filters?.servers)) {
                state.servers = data.filters.servers;
            }
        })
        .catch((error) => {
            console.error('[metrics-dashboard] Failed to load chart data', error);
            showMetricsError(container, 'Unable to load metrics data. Please try again.');
        })
        .finally(() => {
            toggleMetricsLoading(container, false);
        });
}

function toggleMetricsLoading(container, isLoading) {
    const spinner = container.querySelector('.js-metrics-loading');
    if (!spinner) {
        return;
    }

    spinner.classList.toggle('is-hidden', !isLoading);
}

function updateMetricButtons(container, activeMetric) {
    container.querySelectorAll('[data-metric]').forEach((button) => {
        const isActive = button.getAttribute('data-metric') === activeMetric;
        button.classList.toggle('is-link', isActive);
        button.classList.toggle('is-light', isActive);
        button.classList.toggle('is-white', !isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

function updateTimespanButtons(container, activeTimespan) {
    container.querySelectorAll('[data-timespan]').forEach((button) => {
        const isActive = button.getAttribute('data-timespan') === activeTimespan;
        button.classList.toggle('is-link', isActive);
        button.classList.toggle('is-light', isActive);
        button.classList.toggle('is-white', !isActive);
        button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
}

function renderMetricsChart(container, payload) {
    const state = metricsDashboards.get(container);
    if (!state) {
        return;
    }

    const canvas = container.querySelector('canvas');
    if (!canvas) {
        console.warn('[metrics-dashboard] Canvas element not found.');
        return;
    }

    const chartData = Array.isArray(payload.datasets) ? payload.datasets : [];
    const labels = Array.isArray(payload.labels) ? payload.labels : [];
    const unit = payload.stats?.unit || '';

    state.unit = unit;

    const datasets = chartData.map((dataset) => ({
        // Server colors come from servers.json; only the fallbacks and points follow the theme.
        themeColors: {
            ...(dataset.borderColor ? {} : { borderColor: '--chart-line' }),
            ...(dataset.backgroundColor ? {} : { backgroundColor: '--chart-fill' }),
            pointBackgroundColor: '--chart-point',
        },
        label: dataset.label,
        data: Array.isArray(dataset.data) ? dataset.data : [],
        borderColor: dataset.borderColor || window.mataThemeColor('--chart-line'),
        backgroundColor: dataset.backgroundColor || window.mataThemeColor('--chart-fill'),
        fill: Boolean(dataset.fill),
        tension: typeof dataset.tension === 'number' ? dataset.tension : 0.32,
        spanGaps: dataset.spanGaps !== false,
        pointRadius: typeof dataset.pointRadius === 'number' ? dataset.pointRadius : 2,
        pointHoverRadius: typeof dataset.pointHoverRadius === 'number' ? dataset.pointHoverRadius : 4,
        pointBackgroundColor: window.mataThemeColor('--chart-point'),
        borderWidth: typeof dataset.borderWidth === 'number' ? dataset.borderWidth : 2,
    }));

    if (!state.chart) {
        const context = canvas.getContext('2d');
        if (!context) {
            console.warn('[metrics-dashboard] Unable to acquire 2D context.');
            return;
        }

        state.chart = new window.Chart(context, buildChartConfig(labels, datasets, unit, payload));
        return;
    }

    state.chart.data.labels = labels;
    state.chart.data.datasets = datasets;
    const yScale = state.chart.options?.scales?.y;
    if (yScale) {
        yScale.title = yScale.title || {};
        yScale.title.display = unit !== '';
        yScale.title.text = unit;
    }
    state.chart.update('none');
}

function buildChartConfig(labels, datasets, unit, payload) {
    return {
        type: payload.chart_type || 'line',
        data: {
            labels,
            datasets,
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: {
                    position: 'bottom',
                },
                tooltip: {
                    callbacks: {
                        label(context) {
                            const value = context.parsed.y;
                            const label = context.dataset.label || '';
                            const formatted = formatMetricValue(value, unit);
                            return `${label}: ${formatted}`;
                        },
                    },
                },
            },
            scales: {
                x: {
                    grid: {
                        display: false,
                    },
                    ticks: {
                        maxRotation: 0,
                        autoSkip: true,
                    },
                },
                y: {
                    beginAtZero: false,
                    title: {
                        display: unit !== '',
                        text: unit,
                    },
                    ticks: {
                        callback(value) {
                            return formatMetricValue(value, unit);
                        },
                    },
                },
            },
        },
    };
}

function renderMetricsStats(container, payload) {
    const statsRoot = container.querySelector('.js-metrics-stats');
    if (!statsRoot) {
        return;
    }

    const hasLabels = Array.isArray(payload.labels) && payload.labels.length > 0;
    if (!hasLabels) {
        statsRoot.innerHTML = '';
        return;
    }

    statsRoot.innerHTML = '';

    const stats = payload.stats || {};
    const servers = Array.isArray(stats.servers) ? stats.servers : [];

    const summary = document.createElement('div');
    summary.className = 'metrics-dashboard__summary mb-3';

    const summaryTitle = document.createElement('p');
    summaryTitle.className = 'has-text-weight-semibold is-size-6';
    summaryTitle.textContent = `${stats.metric_label ?? stats.metric ?? ''} · ${stats.timespan_label ?? stats.timespan ?? ''}`;
    summary.appendChild(summaryTitle);

    statsRoot.appendChild(summary);

    if (servers.length === 0) {
        const placeholder = document.createElement('div');
        placeholder.className = 'notification is-light is-size-7';
        placeholder.textContent = 'No metrics available for the selected filters.';
        statsRoot.appendChild(placeholder);
        return;
    }

    servers.forEach((stat) => {
        const card = document.createElement('div');
        card.className = 'box metrics-dashboard__stat-card';

        const title = document.createElement('p');
        title.className = 'title is-6 mb-2';
        title.textContent = stat.label ?? stat.name ?? 'Server';
        card.appendChild(title);

        const list = document.createElement('dl');
        list.className = 'is-size-7 metrics-dashboard__stat-list';

        appendStatRow(list, 'Current', stat.current, stats.unit);
        appendStatRow(list, 'Average', stat.avg, stats.unit);
        appendStatRow(list, 'Max', stat.max, stats.unit);
        appendStatRow(list, 'Min', stat.min, stats.unit);

        card.appendChild(list);
        statsRoot.appendChild(card);
    });
}

function appendStatRow(list, label, value, unit) {
    const term = document.createElement('dt');
    term.textContent = label;
    term.className = 'has-text-grey-dark';
    list.appendChild(term);

    const description = document.createElement('dd');
    description.textContent = formatMetricValue(value, unit);
    description.className = 'has-text-weight-semibold has-text-grey-darker';
    list.appendChild(description);
}

function renderCacheMetadata(container, cache) {
    const element = container.querySelector('.js-metrics-cache');
    if (!element) {
        return;
    }

    if (!cache) {
        element.textContent = 'Cache: disabled';
        return;
    }

    const parts = [];
    parts.push(cache.from_cache ? 'cached hit' : 'fresh');

    if (typeof cache.age === 'number') {
        parts.push(`age ${Math.round(cache.age)}s`);
    }

    if (typeof cache.ttl === 'number' && cache.ttl > 0) {
        parts.push(`ttl ${cache.ttl}s`);
    }

    element.textContent = `Cache: ${parts.join(' · ')}`;
}

function toggleEmptyState(container, isEmpty) {
    const empty = container.querySelector('.js-metrics-empty');
    if (empty) {
        empty.classList.toggle('is-hidden', !isEmpty);
    }
}

function showMetricsError(container, message) {
    const error = container.querySelector('.js-metrics-error');
    if (!error) {
        return;
    }
    error.textContent = message;
    error.classList.remove('is-hidden');
}

function hideMetricsError(container) {
    const error = container.querySelector('.js-metrics-error');
    if (!error) {
        return;
    }
    error.classList.add('is-hidden');
}

function destroyMetricsDashboard(container) {
    const state = metricsDashboards.get(container);
    if (!state) {
        return;
    }

    if (state.chart) {
        state.chart.destroy();
    }

    metricsDashboards.delete(container);
}

function parseServers(raw) {
    if (!raw) {
        return [];
    }

    try {
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
        console.warn('[metrics-dashboard] Unable to parse servers data', error);
        return [];
    }
}

function formatMetricValue(value, unit) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return 'n/a';
    }

    const numeric = Number(value);

    if (unit === '%') {
        return `${numeric.toFixed(1)}%`;
    }

    if (unit === 'bytes') {
        return formatBytes(numeric);
    }

    if (unit === 'kilobytes') {
        return formatKilobytes(numeric);
    }

    if (Math.abs(numeric) >= 1000) {
        return numeric.toLocaleString();
    }

    return numeric.toString();
}

function formatKilobytes(kilobytes) {
    return formatBytes(kilobytes * 1024);
}

function formatBytes(bytes) {
    if (!Number.isFinite(bytes)) {
        return 'n/a';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let size = bytes;
    let unitIndex = 0;

    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex += 1;
    }

    return `${size.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
}

// ────────────────────────────────────────────────
// Event Listeners - Bootstrap & Cleanup
// ────────────────────────────────────────────────

initializeMetricsDashboards(document);

document.body.addEventListener('htmx:afterSwap', (event) => {
    if (event.target instanceof Element) {
        initializeMetricsDashboards(event.target);
    }
});

document.body.addEventListener('htmx:beforeSwap', (event) => {
    const target = event.target instanceof Element ? event.target : event.detail?.target;
    if (!target) {
        return;
    }

    target.querySelectorAll('.js-metrics-dashboard').forEach((container) => {
        destroyMetricsDashboard(container);
    });
});
