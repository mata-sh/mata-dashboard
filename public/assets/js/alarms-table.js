(function () {
    'use strict';

    var deliveryStyles = {
        sent: 'is-success',
        pending: 'is-info',
        processing: 'is-info',
        retry: 'is-warning',
        throttled: 'is-warning',
        failed: 'is-danger',
        discarded: 'is-danger',
    };

    function formatType(cell) {
        var element = document.createElement('span');
        element.style.fontWeight = 'bold';
        element.textContent = String(cell.getValue() || '');

        return element;
    }

    function shortenedMessage(message) {
        var characters = Array.from(message);

        return characters.length > 50 ? characters.slice(0, 50).join('') + '…' : message;
    }

    function formatMessage(cell) {
        var message = String(cell.getValue() || '');
        var truncated = Array.from(message).length > 50;
        var element = document.createElement(truncated ? 'button' : 'span');

        if (truncated) {
            element.type = 'button';
            element.className = 'table-message-trigger';
            element.title = 'Show the full message';
            element.setAttribute('aria-label', 'Show the full message');
        }

        element.textContent = shortenedMessage(message);

        return element;
    }

    function showMessage(cell) {
        var message = String(cell.getValue() || '');
        if (Array.from(message).length <= 50 || !window.mataOpenMessageModal) {
            return;
        }

        window.mataOpenMessageModal(message, cell.getElement().querySelector('.table-message-trigger'));
    }

    function formatDeliveries(cell) {
        var deliveries = cell.getValue();
        if (!deliveries || typeof deliveries !== 'object' || Array.isArray(deliveries)) {
            return '—';
        }

        var channels = Object.keys(deliveries).sort();
        if (channels.length === 0) {
            return '—';
        }

        var container = document.createElement('div');
        channels.forEach(function (channel) {
            var delivery = deliveries[channel] || {};
            var status = String(delivery.status || '').toLowerCase();
            var label = status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown';
            var line = document.createElement('div');
            var tag = document.createElement('span');
            tag.className = 'tag ' + (deliveryStyles[status] || 'is-light');
            tag.textContent = channel + ': ' + label;
            line.appendChild(tag);
            container.appendChild(line);
        });

        return container;
    }

    function formatAcknowledged(cell) {
        var rowData = cell.getRow().getData();
        if (!rowData.acknowledged_at) {
            return '—';
        }

        var badge = document.createElement('span');
        badge.className = 'cve-badge cve-ok';
        badge.dataset.tippyContent = 'Acknowledged by ' + (rowData.acknowledged_by || 'unknown') + ' at ' + rowData.acknowledged_at;
        badge.setAttribute('aria-label', badge.dataset.tippyContent);
        badge.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>';

        return badge;
    }

    function destroyAlarmsTable(context) {
        var root = context instanceof Element ? context : document;
        var tableElement = root.matches && root.matches('#alarms-table')
            ? root
            : root.querySelector('#alarms-table');
        var table = tableElement && tableElement._mataTabulator;

        if (table && typeof table.destroy === 'function') {
            table.destroy();
            tableElement._mataTabulator = null;
        }
    }

    function initializeAlarmsTable(context) {
        var root = context instanceof Element ? context : document;
        var tableElement = root.matches && root.matches('#alarms-table')
            ? root
            : root.querySelector('#alarms-table');
        if (!tableElement || tableElement.dataset.initialized || typeof Tabulator === 'undefined') {
            return;
        }

        var alarmsUrl = tableElement.dataset.alarmsUrl;
        var acknowledgeUrl = tableElement.dataset.acknowledgeUrl;
        if (!alarmsUrl || !acknowledgeUrl) {
            return;
        }

        tableElement.dataset.initialized = 'true';
        var table = new Tabulator(tableElement, {
            ajaxURL: alarmsUrl,
            layout: 'fitDataStretch',
            height: '1200px',
            pagination: 'local',
            paginationSize: 33,
            paginationCounter: 'rows',
            initialSort: [{column: 'id', dir: 'desc'}],
            ajaxResponse: function (url, params, response) {
                if (window.mataPopulateSelectFromRows) {
                    window.mataPopulateSelectFromRows('filter-app', response, 'app', 'All Apps');
                    window.mataPopulateSelectFromRows('filter-server', response, 'server', 'All Servers');
                }

                return response;
            },
            columns: [
                {title: 'ID', field: 'id', sorter: 'number', hozAlign: 'center'},
                {title: 'Datetime', field: 'datetime', sorter: 'datetime', hozAlign: 'center'},
                {title: 'Server', field: 'server', sorter: 'string', hozAlign: 'center'},
                {title: 'App', field: 'app', sorter: 'string', hozAlign: 'center'},
                {title: 'Level', field: 'level', hozAlign: 'center', formatter: window.mataFormatLevel},
                {title: 'Type', field: 'type', sorter: 'string', formatter: formatType},
                {title: 'Message', field: 'message', sorter: 'string', cssClass: 'table-message-cell', formatter: formatMessage, cellClick: function (event, cell) { showMessage(cell); }},
                {title: 'Context', field: 'context', sorter: 'string'},
                {title: 'Deliveries', field: 'deliveries', hozAlign: 'center', formatter: formatDeliveries},
                {title: 'Acknowledged', field: 'acknowledged_at', width: 130, hozAlign: 'center', sorter: 'string', formatter: formatAcknowledged},
                {
                    title: '', field: 'actions', headerSort: false, sorter: false, hozAlign: 'center',
                    formatter: function (cell) {
                        if (cell.getRow().getData().acknowledged_at) {
                            return '';
                        }

                        return '<button type="button" class="button is-small alarm-ack-btn">Acknowledge</button>';
                    },
                    cellClick: function (event, cell) {
                        if (!(event.target instanceof Element) || !event.target.closest('.alarm-ack-btn')) {
                            return;
                        }

                        var csrfToken = document.querySelector('meta[name="csrf-token"]');
                        if (!csrfToken) {
                            return;
                        }

                        var rowData = cell.getRow().getData();
                        fetch(acknowledgeUrl.replace('{alarmId}', encodeURIComponent(rowData.id)), {
                            method: 'POST',
                            headers: {'X-CSRF-Token': csrfToken.content},
                        })
                            .then(function (response) {
                                if (!response.ok) {
                                    throw new Error('Could not acknowledge alarm');
                                }

                                return response.json();
                            })
                            .then(function (response) {
                                if (!response.success) {
                                    return;
                                }

                                var unacknowledgedOnly = document.getElementById('filter-unacknowledged').checked;
                                table.setData(alarmsUrl + (unacknowledgedOnly ? '?unacknowledged=1' : ''));
                                document.body.dispatchEvent(new Event('bell-refresh'));
                            })
                            .catch(function (error) {
                                console.error(error);
                            });
                    },
                },
            ],
            renderComplete: function () {
                tippy('#alarms-table [data-tippy-content]', {allowHTML: true});
            },
        });

        tableElement._mataTabulator = table;

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
        document.getElementById('filter-unacknowledged').addEventListener('change', function (event) {
            table.setData(alarmsUrl + (event.currentTarget.checked ? '?unacknowledged=1' : ''));
        });
        document.getElementById('filter-clear').addEventListener('click', function () {
            ['filter-id', 'filter-type', 'filter-time', 'filter-msg'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            ['filter-level', 'filter-app', 'filter-server'].forEach(function (id) {
                document.getElementById(id).value = '';
            });
            document.getElementById('filter-unacknowledged').checked = false;
            table.clearFilter();
            table.setData(alarmsUrl);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initializeAlarmsTable(document);
    });
    document.body.addEventListener('htmx:beforeSwap', function (event) {
        destroyAlarmsTable(event.detail.target);
    });
    document.body.addEventListener('htmx:afterSwap', function (event) {
        initializeAlarmsTable(event.detail.target);
    });
}());
