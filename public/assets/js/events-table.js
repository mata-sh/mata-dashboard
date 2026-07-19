(function () {
    'use strict';

    function formatDatetime(cell) {
        var value = String(cell.getValue() || '');
        var isoValue = value.includes('T') ? value : value.replace(' ', 'T');
        if (!/(?:Z|[+-]\d{2}:?\d{2})$/i.test(isoValue)) {
            isoValue += 'Z';
        }

        var date = new Date(isoValue);
        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toISOString().slice(0, 16).replace('T', ' ') + ' UTC';
    }

    function formatType(cell) {
        var element = document.createElement('span');
        element.style.fontWeight = 'bold';
        element.textContent = String(cell.getValue() || '');

        return element;
    }

    function initializeEventsTable(context) {
        var root = context instanceof Element ? context : document;
        var tableElement = root.matches && root.matches('#events-table')
            ? root
            : root.querySelector('#events-table');

        if (!tableElement || tableElement.dataset.initialized || typeof Tabulator === 'undefined') {
            return;
        }

        var eventsUrl = tableElement.dataset.eventsUrl;
        if (!eventsUrl) {
            return;
        }

        tableElement.dataset.initialized = 'true';
        var table = new Tabulator(tableElement, {
            ajaxURL: eventsUrl,
            layout: 'fitDataStretch',
            height: '1200px',
            pagination: 'local',
            paginationSize: 33,
            paginationCounter: 'rows',
            ajaxResponse: function (url, params, response) {
                if (window.mataPopulateSelectFromRows) {
                    window.mataPopulateSelectFromRows('filter-app', response, 'app', 'All Apps');
                    window.mataPopulateSelectFromRows('filter-server', response, 'server', 'All Servers');
                }

                return response;
            },
            columns: [
                {title: 'ID', field: 'id', sorter: 'number', hozAlign: 'center'},
                {title: 'Datetime', field: 'datetime', sorter: 'datetime', hozAlign: 'center', formatter: formatDatetime},
                {title: 'Server', field: 'server', sorter: 'string', hozAlign: 'center'},
                {title: 'App', field: 'app', sorter: 'string', hozAlign: 'center'},
                {title: 'Level', field: 'level', hozAlign: 'center', formatter: window.mataFormatLevel},
                {title: 'Type', field: 'type', sorter: 'string', formatter: formatType},
                {title: 'Message', field: 'message', sorter: 'string'},
                {title: 'Context', field: 'context', sorter: 'string'},
            ],
        });

        function applyFilters() {
            var filters = [];
            var values = {
                id: document.getElementById('filter-id').value.trim(),
                level: document.getElementById('filter-level').value,
                app: document.getElementById('filter-app').value,
                server: document.getElementById('filter-server').value,
                type: document.getElementById('filter-type').value.trim(),
                message: document.getElementById('filter-msg').value.trim(),
                datetime: document.getElementById('filter-time').value.trim(),
            };

            Object.entries(values).forEach(function (entry) {
                if (entry[1]) {
                    filters.push({field: entry[0], type: entry[0] === 'level' ? '=' : 'like', value: entry[1]});
                }
            });
            table.setFilter(filters);
        }

        ['filter-id', 'filter-type', 'filter-time', 'filter-msg'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', applyFilters);
        });
        ['filter-level', 'filter-app', 'filter-server'].forEach(function (id) {
            document.getElementById(id).addEventListener('change', applyFilters);
        });
        document.getElementById('filter-clear').addEventListener('click', function () {
            ['filter-id', 'filter-type', 'filter-time', 'filter-msg'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            ['filter-level', 'filter-app', 'filter-server'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            table.clearFilter();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeEventsTable(document);
    });
    document.body.addEventListener('htmx:afterSwap', function (event) {
        initializeEventsTable(event.detail.target);
    });
}());
