(function () {
    'use strict';

    function formatType(cell) {
        var value = String(cell.getValue() || '');
        var element = document.createElement('span');
        element.style.fontWeight = 'bold';
        if (value === 'Error') {
            element.style.color = 'red';
        } else if (value === 'Warning') {
            element.style.color = 'orange';
        } else if (value === 'Deprecated') {
            element.style.color = '#3FB449';
        }
        element.textContent = value;

        return element;
    }

    function destroyLogsTable(context) {
        // htmx also cleans up text nodes; falling back to document would destroy the table on any swap.
        if (!(context instanceof Element)) {
            return;
        }
        var tableElement = context.matches('#logs-table')
            ? context
            : context.querySelector('#logs-table');
        var table = tableElement && tableElement._mataTabulator;

        if (table && typeof table.destroy === 'function') {
            tableElement._mataTabulator = null;
            tableElement._mataRemoveFilters();
            table.destroy();
            delete tableElement.dataset.initialized;
        }
    }

    function initializeLogsTable(context) {
        var root = context instanceof Element ? context : document;
        var tableElement = root.matches && root.matches('#logs-table')
            ? root
            : root.querySelector('#logs-table');

        if (!tableElement || tableElement.dataset.initialized || typeof Tabulator === 'undefined') {
            return;
        }

        var logsUrl = tableElement.dataset.logsUrl;
        if (!logsUrl) {
            return;
        }

        tableElement.dataset.initialized = 'true';
        var table = new Tabulator(tableElement, {
            ajaxURL: logsUrl,
            layout: 'fitDataFill',
            height: '1200px',
            pagination: 'local',
            paginationSize: 33,
            columns: [
                {title: 'Timestamp', field: 'timestamp', sorter: 'datetime', hozAlign: 'center', width: 180},
                {title: 'Type', field: 'type', hozAlign: 'center', width: 120, formatter: formatType},
                {title: 'Source', field: 'source', sorter: 'string', hozAlign: 'left', width: 250},
                {title: 'Message', field: 'message', sorter: 'string'},
            ],
        });

        tableElement._mataTabulator = table;

        function applyFilters() {
            var filters = [];
            var typeVal = document.getElementById('filter-type').value;
            var sourceVal = document.getElementById('filter-source').value.trim();
            var msgVal = document.getElementById('filter-msg').value.trim();
            var timeVal = document.getElementById('filter-time').value.trim();

            if (typeVal) filters.push({field: 'type', type: '=', value: typeVal});
            if (sourceVal) filters.push({field: 'source', type: 'like', value: sourceVal});
            if (msgVal) filters.push({field: 'message', type: 'like', value: msgVal});
            if (timeVal) filters.push({field: 'timestamp', type: 'like', value: timeVal});

            table.setFilter(filters);
        }

        var inputs = ['filter-time', 'filter-source', 'filter-msg'].map(function (id) {
            return document.getElementById(id);
        });
        var typeSelect = document.getElementById('filter-type');
        var clearButton = document.getElementById('filter-clear');
        inputs.forEach(function (input) {
            input.addEventListener('input', applyFilters);
        });
        typeSelect.addEventListener('change', applyFilters);
        function clearFilters() {
            ['filter-type', 'filter-time', 'filter-source', 'filter-msg'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            table.clearFilter();
        }
        clearButton.addEventListener('click', clearFilters);
        tableElement._mataRemoveFilters = function () {
            inputs.forEach(function (input) {
                input.removeEventListener('input', applyFilters);
            });
            typeSelect.removeEventListener('change', applyFilters);
            clearButton.removeEventListener('click', clearFilters);
            delete tableElement._mataRemoveFilters;
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeLogsTable(document);
    });
    document.body.addEventListener('htmx:beforeCleanupElement', function (event) {
        destroyLogsTable(event.detail.elt);
    });
    document.body.addEventListener('htmx:afterSettle', function (event) {
        initializeLogsTable(event.target);
    });
}());
