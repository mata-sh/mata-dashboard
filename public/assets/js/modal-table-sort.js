// Click-to-sort for server-rendered modal tables.
//
// Opt a table in by adding `data-sort="number|text"` to any `<th>`. Cells are
// compared by their `data-sort-value` when present, otherwise by cell text.
// Delegated from the document so htmx-swapped modals work without re-init.

function sortValue(row, index, type) {
    const cell = row.children[index];
    if (!cell) {
        return type === 'number' ? Number.NEGATIVE_INFINITY : '';
    }

    const raw = cell.dataset.sortValue ?? cell.textContent.trim();

    if (type !== 'number') {
        return raw.toLowerCase();
    }

    const numeric = parseFloat(raw.replace(/[^0-9.eE+-]/g, ''));

    return Number.isNaN(numeric) ? Number.NEGATIVE_INFINITY : numeric;
}

function sortModalTable(header) {
    const table = header.closest('.modal-table');
    const body = table?.querySelector('tbody');
    if (!body) {
        return;
    }

    const headers = Array.from(header.parentElement.children);
    const index = headers.indexOf(header);
    const type = header.dataset.sort;
    const current = header.getAttribute('aria-sort');

    // 1 = ascending, -1 = descending. An unsorted column opens on the most
    // useful direction: biggest first for numbers, A–Z for text.
    let direction = type === 'number' ? -1 : 1;
    if (current) {
        direction = current === 'descending' ? 1 : -1;
    }

    const rows = Array.from(body.rows).sort((a, b) => {
        const left = sortValue(a, index, type);
        const right = sortValue(b, index, type);

        return left < right ? -direction : (left > right ? direction : 0);
    });

    headers.forEach((th) => th.removeAttribute('aria-sort'));
    header.setAttribute('aria-sort', direction === 1 ? 'ascending' : 'descending');

    rows.forEach((row) => body.appendChild(row));
}

document.addEventListener('click', function (event) {
    const header = event.target.closest('.modal-table th[data-sort]');
    if (header) {
        sortModalTable(header);
    }
});
