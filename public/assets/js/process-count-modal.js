(function () {
    'use strict';

    var charts = new WeakMap();

    function formatValue(value) {
        return (value === null || value === undefined) ? '—' : value;
    }

    function hasDataPoints(series) {
        return Array.isArray(series.data) && series.data.some(function (value) {
            return value !== null && value !== undefined;
        });
    }

    function updateStats(container, series) {
        ['min', 'max', 'avg'].forEach(function (key) {
            var element = container.querySelector('[data-metric-stat="' + key + '"]');
            if (element) element.textContent = formatValue(series[key]);
        });
        var unit = container.querySelector('[data-metric-stat="unit"]');
        if (unit) unit.textContent = series.unit || '';
    }

    function renderSeries(container, series) {
        var state = charts.get(container);
        if (!state) return;

        var hasData = hasDataPoints(series);
        var emptyState = container.querySelector('.metrics-chart-empty');

        if (!hasData) {
            state.canvas.style.display = 'none';
            if (emptyState) emptyState.removeAttribute('hidden');
        } else {
            state.canvas.style.display = 'block';
            if (emptyState) emptyState.setAttribute('hidden', '');

            if (state.chart) state.chart.destroy();

            state.chart = new Chart(state.canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: series.labels,
                    datasets: [{
                        label: state.metricLabel,
                        data: series.data,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.20)',
                        fill: true,
                        tension: 0.3,
                        spanGaps: true,
                    }],
                },
                options: {
                    maintainAspectRatio: false,
                    responsive: true,
                    scales: {
                        x: {ticks: {autoSkip: true, maxTicksLimit: 10}},
                        y: {beginAtZero: true, ticks: {precision: 0}},
                    },
                    plugins: {
                        legend: {display: true},
                        tooltip: {mode: 'index', intersect: false},
                    },
                },
            });
        }

        updateStats(container, series);
    }

    function setupProcessCountModal(container) {
        if (charts.has(container)) return;

        var elementId = container.dataset.elementId;
        var canvas = document.getElementById(elementId);
        if (!canvas) {
            console.warn('Metrics modal: chart element not found', elementId);
            return;
        }

        if (window.Chart === undefined) {
            canvas.insertAdjacentHTML('afterend', '<p class="notification is-warning mt-3">Chart.js is required to render this chart.</p>');
            return;
        }

        var state = {
            canvas: canvas,
            metricLabel: container.dataset.metricLabel || '',
            chart: null,
        };
        charts.set(container, state);

        var initialSeries = JSON.parse(container.dataset.initialSeries || '{}');
        renderSeries(container, initialSeries);

        var jsonUrl = container.dataset.apiEndpoint;
        if (jsonUrl) {
            fetch(jsonUrl, {
                headers: {Accept: 'application/json'},
                credentials: 'same-origin',
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Metrics request failed with status ' + response.status);
                    }
                    return response.json();
                })
                .then(function (payload) {
                    if (charts.get(container) === state && payload && payload.series) {
                        renderSeries(container, payload.series);
                    }
                })
                .catch(function (error) {
                    console.warn('Metrics modal: failed to refresh chart data', error);
                });
        }
    }

    function destroyProcessCountModal(container) {
        var state = charts.get(container);
        if (!state) return;
        charts.delete(container);
        if (state.chart) state.chart.destroy();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.js-process-count-modal').forEach(setupProcessCountModal);
    });
    document.body.addEventListener('htmx:afterSwap', function (event) {
        var target = event.target instanceof Element ? event.target : event.detail && event.detail.target;
        if (target && target.querySelectorAll) {
            if (target.matches('.js-process-count-modal')) setupProcessCountModal(target);
            target.querySelectorAll('.js-process-count-modal').forEach(setupProcessCountModal);
        }
    });
    document.body.addEventListener('htmx:beforeCleanupElement', function (event) {
        var target = event.detail.elt;
        if (target && target.querySelectorAll) {
            if (target.matches('.js-process-count-modal')) destroyProcessCountModal(target);
            target.querySelectorAll('.js-process-count-modal').forEach(destroyProcessCountModal);
        }
    });
    document.body.addEventListener('closeModal', function () {
        document.querySelectorAll('.js-process-count-modal').forEach(destroyProcessCountModal);
    }, true);
}());
