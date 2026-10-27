/*
 * Mountaineer IT - client portal tables on a phone: ITFlow's portal pages put their tables straight in the page, so a wide one
 * made the whole page wider than the screen. Each table not already in a .table-responsive gets Bootstrap's own wrapper (it
 * then scrolls sideways inside its box; nothing changes on a wider screen). Loaded from client/includes/footer.php before
 * DataTables, so DataTables builds its search and paging around the wrapped table.
 */
document.querySelectorAll('table.table').forEach(function (table) {
    if (table.closest('.table-responsive')) {
        return;
    }
    var wrap = document.createElement('div');
    wrap.className = 'table-responsive';
    table.parentNode.insertBefore(wrap, table);
    wrap.appendChild(table);
});
