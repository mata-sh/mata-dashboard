(function () {
    'use strict';

    var SEVERITY_RANK = {critical: 0, high: 1, medium: 2, low: 3, unknown: 4};

    function findTable(context) {
        var root = context instanceof Element ? context : document;

        return root.matches && root.matches('#advisories-table') ? root : root.querySelector('#advisories-table');
    }

    function formatDate(cell) {
        return String(cell.getValue() || '').slice(0, 16);
    }

    function formatSeverity(cell) {
        var row = cell.getRow().getData();
        var element = document.createElement('span');
        element.className = 'audit-pill advisory-severity sev-' + row.severity;
        element.textContent = row.severity_label;

        return element;
    }

    function formatTitle(cell) {
        var row = cell.getRow().getData();
        var wrapper = document.createElement('span');
        var title = document.createElement('button');
        title.type = 'button';
        title.className = 'table-message-trigger advisory-title-trigger';
        title.title = 'Show details and the raw advisory';
        title.textContent = row.title;
        wrapper.appendChild(title);
        if (row.baseline) {
            var badge = document.createElement('span');
            badge.className = 'advisory-baseline-tag';
            badge.title = 'Stored by the first poll of the source, without notifying';
            badge.textContent = 'baseline';
            wrapper.appendChild(badge);
        }

        return wrapper;
    }

    function formatReview(cell) {
        var row = cell.getRow().getData();
        var element = document.createElement('span');
        element.className = 'advisory-review advisory-review-' + row.review_status;
        element.textContent = row.review_label;
        if (row.review_note) {
            element.title = row.review_note;
        }

        return element;
    }

    function formatMono(cell) {
        var element = document.createElement('span');
        element.className = 'advisory-mono';
        element.textContent = String(cell.getValue() || '');

        return element;
    }

    function formatMuted(cell) {
        var element = document.createElement('span');
        element.className = 'advisory-muted';
        element.textContent = String(cell.getValue() || '');

        return element;
    }

    function formatNotified(cell) {
        return cell.getValue() ? '✓' : '';
    }

    function destroyAdvisoriesTable(context) {
        var tableElement = findTable(context);
        var table = tableElement && tableElement._mataTabulator;
        if (table && typeof table.destroy === 'function') {
            table.destroy();
            tableElement._mataTabulator = null;
            delete tableElement.dataset.initialized;
        }
    }

    function initializeAdvisoriesTable(context) {
        var tableElement = findTable(context);
        if (!tableElement || tableElement.dataset.initialized || typeof Tabulator === 'undefined') {
            return;
        }
        var baseUrl = tableElement.dataset.advisoriesUrl;
        var detailUrl = tableElement.dataset.detailUrl;
        if (!baseUrl) {
            return;
        }

        var daysSelect = document.getElementById('advisory-filter-days');
        function dataUrl() {
            return baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + 'days=' + encodeURIComponent(daysSelect.value);
        }

        tableElement.dataset.initialized = 'true';
        var table = new Tabulator(tableElement, {
            ajaxURL: dataUrl(),
            layout: 'fitDataStretch',
            height: '1200px',
            pagination: 'local',
            paginationSize: 33,
            paginationCounter: 'rows',
            placeholder: 'No advisories in this period.',
            initialSort: [{column: 'datetime', dir: 'desc'}],
            columns: [
                {title: 'Published', field: 'datetime', sorter: 'string', hozAlign: 'center', formatter: formatDate},
                {title: 'Source', field: 'source', sorter: 'string'},
                {title: 'Severity', field: 'severity', hozAlign: 'center', formatter: formatSeverity,
                    sorter: function (a, b) { return SEVERITY_RANK[a] - SEVERITY_RANK[b]; }},
                {title: 'CVSS', field: 'cvss', sorter: 'number', hozAlign: 'center'},
                {title: 'Title', field: 'title', sorter: 'string', maxWidth: 560, tooltip: true, cssClass: 'table-message-cell', formatter: formatTitle},
                {title: 'CVE', field: 'cves', sorter: 'string', maxWidth: 220, tooltip: true, formatter: formatMono},
                {title: 'Affected', field: 'products', sorter: 'string', maxWidth: 260, tooltip: true, formatter: formatMuted},
                {title: 'Matched', field: 'keywords', sorter: 'string', maxWidth: 160, tooltip: true},
                {title: 'Notified', field: 'notified', hozAlign: 'center', formatter: formatNotified},
                {title: 'Review', field: 'review_status', hozAlign: 'center', minWidth: 130, formatter: formatReview},
            ],
        });
        tableElement._mataTabulator = table;
        table.on('rowClick', function (event, row) {
            openDetail(row.getData().id);
        });

        function openDetail(id) {
            if (!detailUrl || typeof htmx === 'undefined') {
                return;
            }
            htmx.ajax('GET', detailUrl.replace('__ID__', encodeURIComponent(id)), {target: '#modal-container', swap: 'innerHTML'});
        }

        function applyFilters() {
            var severity = document.getElementById('advisory-filter-severity').value;
            var source = document.getElementById('advisory-filter-source').value;
            var review = document.getElementById('advisory-filter-review').value;
            var relevantOnly = document.getElementById('advisory-filter-relevant').checked;
            var text = document.getElementById('advisory-filter-text').value.trim().toLowerCase();

            table.setFilter(function (row) {
                if (severity && row.severity !== severity) {
                    return false;
                }
                if (source && row.source_key !== source) {
                    return false;
                }
                if (review && row.review_status !== review) {
                    return false;
                }
                if (relevantOnly && !row.relevant) {
                    return false;
                }

                return !text || [row.title, row.cves, row.products, row.keywords].join(' ').toLowerCase().indexOf(text) !== -1;
            });
        }

        // Not on tableBuilt: the ajax data pipeline is not ready then.
        table.on('dataProcessed', applyFilters);
        ['advisory-filter-severity', 'advisory-filter-source', 'advisory-filter-review', 'advisory-filter-relevant'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', applyFilters);
        });
        document.getElementById('advisory-filter-text').addEventListener('input', applyFilters);
        daysSelect.addEventListener('change', function () {
            table.setData(dataUrl());
        });
        document.getElementById('advisory-filter-clear').addEventListener('click', function () {
            ['advisory-filter-severity', 'advisory-filter-source', 'advisory-filter-review', 'advisory-filter-text'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            document.getElementById('advisory-filter-relevant').checked = false;
            applyFilters();
        });

        initializeBulkReview(table);
    }

    // Bulk review acts on the rows the filters show, across all pages.
    function initializeBulkReview(table) {
        var statusSelect = document.getElementById('advisory-bulk-status');
        var applyButton = document.getElementById('advisory-bulk-apply');
        var shownCount = 0;
        var saving = false;

        // The button names the chosen status, so it reads as the second half of the dropdown.
        function updateButton() {
            applyButton.textContent = statusSelect.value
                ? 'Set ' + shownCount + ' to ' + statusSelect.options[statusSelect.selectedIndex].text
                : 'Set ' + shownCount + ' shown to…';
            applyButton.disabled = saving || !statusSelect.value || shownCount === 0;
        }

        function fail(message) {
            if (window.mataOpenMessageModal) {
                window.mataOpenMessageModal(message, applyButton, 'Bulk review');
            }
        }

        table.on('dataFiltered', function (filters, rows) {
            shownCount = rows.length;
            updateButton();
        });
        statusSelect.addEventListener('change', updateButton);

        applyButton.addEventListener('click', function () {
            var csrfToken = document.querySelector('meta[name="csrf-token"]');
            var status = statusSelect.value;
            var label = statusSelect.options[statusSelect.selectedIndex].text;
            if (applyButton.disabled || !csrfToken || !applyButton.dataset.reviewUrl) {
                return;
            }

            var shown = table.getData('active');
            var ids = shown.filter(function (row) { return row.review_status !== status; }).map(function (row) { return row.id; });
            if (ids.length === 0) {
                fail('All ' + shown.length + ' shown advisories are already "' + label + '".');

                return;
            }
            var question = 'Set ' + ids.length + ' of ' + shown.length + ' shown advisories to "' + label + '"?';
            if (ids.length < shown.length) {
                question += ' ' + (shown.length - ids.length) + ' already are and keep their review.';
            }
            var note = '';
            if (status === 'new') {
                if (!window.confirm(question + ' This clears their notes and reviewers.')) {
                    return;
                }
            } else {
                note = window.prompt(question + '\n\nOptional note for all of them:', '');
                if (note === null) {
                    return;
                }
            }

            var body = new URLSearchParams();
            ids.forEach(function (id) { body.append('ids[]', id); });
            body.append('status', status);
            body.append('note', note);
            saving = true;
            updateButton();
            fetch(applyButton.dataset.reviewUrl, {method: 'POST', headers: {'X-CSRF-Token': csrfToken.content}, body: body})
                .then(function (response) {
                    if (!response.ok) {
                        return response.json().catch(function () { return {}; }).then(function (data) {
                            throw new Error(data.message || 'Request failed (HTTP ' + response.status + ')');
                        });
                    }
                    statusSelect.value = '';
                    document.body.dispatchEvent(new Event('advisoryReviewed'));
                })
                .catch(function (error) {
                    fail(error.message);
                })
                .finally(function () {
                    saving = false;
                    updateButton();
                });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeAdvisoriesTable(document);
    });
    document.body.addEventListener('htmx:beforeSwap', function (event) {
        destroyAdvisoriesTable(event.detail.target);
    });
    document.body.addEventListener('htmx:afterSettle', function (event) {
        initializeAdvisoriesTable(event.detail.target);
    });
    // A saved review refreshes the modal; the table follows so the Review column is current.
    document.body.addEventListener('advisoryReviewed', function () {
        var tableElement = findTable(document);
        if (tableElement && tableElement._mataTabulator) {
            tableElement._mataTabulator.replaceData();
        }
    });
}());
