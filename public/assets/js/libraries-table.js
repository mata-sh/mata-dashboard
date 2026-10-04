(function () {
    'use strict';

    var checkIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
    var archiveIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>';

    function formatSeverity(cell) {
        var d = cell.getRow().getData();
        if (!d.audited) return '';

        var output = !d.vulns
            ? '<span class="cve-badge cve-ok" title="no known vulnerabilities">' + checkIcon + '</span>'
            : '<span class="cve-badge cve-' + d.severity + '" title="' + d.severity + ': ' + d.vulns + ' advisories">' + d.vulns + '</span>';

        if (d.abandoned) {
            output += ' <span class="cve-badge cve-abandoned" title="abandoned package">' + archiveIcon + '</span>';
        }

        return output;
    }

    function formatDescription(cell) {
        var value = cell.getValue();
        return value.length > 20 ? value.slice(0, 40) + '...' : value;
    }

    function onSeverityClick(event, cell) {
        var data = cell.getRow().getData();
        var advisories = Array.isArray(data.advisories) ? data.advisories : [];
        var replacement = typeof data.abandoned === 'string' && data.abandoned !== '';
        if (!advisories.length && !replacement) return;

        var rowElement = cell.getRow().getElement();
        var existing = rowElement.querySelector('.advisory-details');
        if (existing) {
            existing.remove();
            return;
        }

        var details = document.createElement('div');
        details.className = 'advisory-details';
        advisories.forEach(function (advisory) {
            var item = document.createElement('div');
            var cve = document.createElement('a');
            cve.className = 'advisory-cve';
            cve.target = '_blank';
            cve.rel = 'noopener';
            if (advisory.link) cve.href = advisory.link;
            cve.textContent = advisory.cve || advisory.advisory_id || 'Unknown advisory';
            item.appendChild(cve);

            var title = document.createElement('span');
            title.textContent = ' ' + (advisory.title || '');
            item.appendChild(title);

            var affectedVersions = document.createElement('span');
            affectedVersions.textContent = ' ' + (advisory.affected_versions || '');
            item.appendChild(affectedVersions);
            details.appendChild(item);
        });

        if (replacement) {
            var abandoned = document.createElement('div');
            abandoned.textContent = 'abandoned — replaced by ' + data.abandoned;
            details.appendChild(abandoned);
        }

        rowElement.appendChild(details);
    }

    function destroyLibrariesTable(context) {
        var root = context instanceof Element ? context : document;
        var tableElement = root.matches && root.matches('#libraries-table')
            ? root
            : root.querySelector('#libraries-table');
        var table = tableElement && tableElement._mataTabulator;

        if (table && typeof table.destroy === 'function') {
            tableElement._mataTabulator = null;
            tableElement._mataRemoveFilters();
            table.destroy();
            delete tableElement.dataset.initialized;
        }
    }

    function initializeLibrariesTable(context) {
        var root = context instanceof Element ? context : document;
        var tableElement = root.matches && root.matches('#libraries-table')
            ? root
            : root.querySelector('#libraries-table');

        if (!tableElement || tableElement.dataset.initialized || typeof Tabulator === 'undefined') {
            return;
        }

        var librariesUrl = tableElement.dataset.librariesUrl;
        if (!librariesUrl) {
            return;
        }

        tableElement.dataset.initialized = 'true';
        var table = new Tabulator(tableElement, {
            ajaxURL: librariesUrl,
            layout: 'fitDataStretch',
            pagination: 'local',
            paginationSize: 40,
            paginationCounter: 'rows',
            ajaxResponse: function (url, params, response) {
                var rows = Array.isArray(response) ? response : (response.data || []);
                var errors = Array.isArray(response) ? [] : (response.errors || []);
                var errorsElement = document.getElementById('libraries-errors');
                errorsElement.replaceChildren();
                errorsElement.hidden = errors.length === 0;
                errors.forEach(function (error) {
                    var line = document.createElement('div');
                    line.textContent = error.server + ': ' + error.message;
                    errorsElement.appendChild(line);
                });
                if (window.mataPopulateSelectFromRows) {
                    window.mataPopulateSelectFromRows('filter-app', rows, 'app', 'All Apps');
                }
                return rows;
            },
            columns: [
                {
                    title: 'CVE',
                    field: 'severity',
                    width: 51,
                    headerSort: true,
                    sorter: function (a, b) {
                        var rank = {critical: 5, high: 4, medium: 3, low: 2, unknown: 1};
                        return (rank[a] || 0) - (rank[b] || 0);
                    },
                    formatter: formatSeverity,
                    cellClick: onSeverityClick,
                },
                {title: 'Name', field: 'name'},
                {title: 'Version', field: 'version'},
                {title: 'Server', field: 'server'},
                {title: 'App', field: 'app'},
                {title: 'Description', field: 'description', sorter: 'string', formatter: formatDescription},
            ],
        });

        tableElement._mataTabulator = table;

        function applyFilters() {
            var filters = [];
            var nameVal = document.getElementById('filter-name').value.trim();
            var versionVal = document.getElementById('filter-version').value.trim();
            var appVal = document.getElementById('filter-app').value;
            var serverVal = document.getElementById('filter-server').value;
            var descriptionVal = document.getElementById('filter-description').value.trim();
            var vulnerableOnly = document.getElementById('filter-vulnerable').checked;

            if (nameVal) filters.push({field: 'name', type: 'like', value: nameVal});
            if (versionVal) filters.push({field: 'version', type: 'like', value: versionVal});
            if (appVal) filters.push({field: 'app', type: 'like', value: appVal});
            if (serverVal) filters.push({field: 'server', type: 'like', value: serverVal});
            if (descriptionVal) filters.push({field: 'description', type: 'like', value: descriptionVal});
            if (vulnerableOnly) filters.push({field: 'vulns', type: '>', value: 0});

            table.setFilter(filters);
        }

        var inputs = ['filter-name', 'filter-version', 'filter-description'].map(function (id) {
            return document.getElementById(id);
        });
        var selects = ['filter-app', 'filter-server', 'filter-vulnerable'].map(function (id) {
            return document.getElementById(id);
        });
        var clearButton = document.getElementById('filter-clear');
        inputs.forEach(function (input) {
            input.addEventListener('input', applyFilters);
        });
        selects.forEach(function (select) {
            select.addEventListener('change', applyFilters);
        });
        function clearFilters() {
            ['filter-name', 'filter-version', 'filter-description'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            ['filter-app', 'filter-server'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            document.getElementById('filter-vulnerable').checked = false;
            table.clearFilter();
        }
        clearButton.addEventListener('click', clearFilters);
        tableElement._mataRemoveFilters = function () {
            inputs.forEach(function (input) {
                input.removeEventListener('input', applyFilters);
            });
            selects.forEach(function (select) {
                select.removeEventListener('change', applyFilters);
            });
            clearButton.removeEventListener('click', clearFilters);
            delete tableElement._mataRemoveFilters;
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeLibrariesTable(document);
    });
    document.body.addEventListener('htmx:beforeCleanupElement', function (event) {
        destroyLibrariesTable(event.detail.elt);
    });
    document.body.addEventListener('htmx:afterSettle', function (event) {
        initializeLibrariesTable(event.target);
    });
}());
