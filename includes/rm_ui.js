/**
 * NEW FILE: includes/rm_ui.js  (loaded by includes/sidebar.php, for RISE MOTIVE only)
 * Task 5: a clean, compact, responsive Staff Portal on every page, without editing each page.
 *   - Tables not already wrapped scroll sideways inside their card (the page never stretches).
 *   - On small screens (< 768px), a table cell with 3 or more actions becomes a compact "⋮" menu that lists
 *     every action with its full name. Buttons, links and forms keep working exactly as before (same
 *     confirmations, same POST forms); a destructive action keeps its red colour.
 *   - Page headers with several buttons wrap instead of running off the screen.
 */
(function () {
    'use strict';
    var SMALL = 768;

    function wrapTables(root) {
        root.querySelectorAll('table').forEach(function (t) {
            if (t.closest('.table-responsive, [style*="overflow-x"], .dropdown-menu, .rm-no-wrap')) { return; }
            var w = document.createElement('div');
            w.className = 'table-responsive';
            t.parentNode.insertBefore(w, t);
            w.appendChild(t);
        });
    }

    function actionsOf(cell) {
        // Direct actions: links/buttons styled as buttons, and small forms holding one submit button.
        var out = [];
        Array.prototype.forEach.call(cell.children, function (el) {
            if (el.matches('a.btn, a.rm-btn, button.btn, button.rm-btn')) { out.push(el); }
            else if (el.tagName === 'FORM' && el.querySelector('button, input[type=submit]')) { out.push(el); }
            else if (el.tagName === 'SPAN' && el.children.length === 0) { /* small icons/labels stay */ }
        });
        return out;
    }

    function labelOf(el) {
        var b = el.tagName === 'FORM' ? el.querySelector('button, input[type=submit]') : el;
        return (b.getAttribute('title') || b.textContent || b.value || '').replace(/\s+/g, ' ').trim();
    }

    function isDanger(el) {
        var b = el.tagName === 'FORM' ? el.querySelector('button, input[type=submit]') : el;
        return /danger/.test(b.className) || /delete|remove|deactivate|cancel|reject/i.test(labelOf(el));
    }

    function collapse(cell) {
        if (cell.dataset.rmCollapsed) { return; }
        var actions = actionsOf(cell);
        if (actions.length < 3) { return; }
        cell.dataset.rmCollapsed = '1';
        var holder = document.createElement('div');
        holder.className = 'dropdown rm-actions-menu';
        holder.innerHTML = '<button type="button" class="btn btn-sm btn-light border rm-kebab" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Actions" title="Actions">'
            + '<i class="bi bi-three-dots-vertical"></i></button><ul class="dropdown-menu dropdown-menu-end shadow-sm"></ul>';
        var list = holder.querySelector('ul');
        actions.forEach(function (el) {
            var li = document.createElement('li');
            li.dataset.rmFrom = '1';
            el.dataset.rmClass = el.className;
            if (el.tagName === 'FORM') {
                var b = el.querySelector('button, input[type=submit]');
                b.dataset.rmClass = b.className;
                b.className = 'dropdown-item' + (isDanger(el) ? ' text-danger' : '');
                el.style.display = 'block';
            } else {
                el.className = 'dropdown-item' + (isDanger(el) ? ' text-danger' : '');
            }
            li.appendChild(el);
            list.appendChild(li);
        });
        cell.appendChild(holder);
    }

    function expand(cell) {
        if (!cell.dataset.rmCollapsed) { return; }
        var holder = cell.querySelector('.rm-actions-menu');
        if (holder) {
            holder.querySelectorAll('li[data-rm-from] > *').forEach(function (el) {
                if (el.tagName === 'FORM') {
                    var b = el.querySelector('button, input[type=submit]');
                    if (b && b.dataset.rmClass !== undefined) { b.className = b.dataset.rmClass; }
                    el.style.display = '';
                } else if (el.dataset.rmClass !== undefined) {
                    el.className = el.dataset.rmClass;
                }
                cell.insertBefore(el, holder);
            });
            holder.remove();
        }
        delete cell.dataset.rmCollapsed;
    }

    function actionCells(root) {
        // Cells that hold several actions (usually the last "Action" column).
        return Array.prototype.filter.call(root.querySelectorAll('td'), function (td) { return actionsOf(td).length >= 3 || td.dataset.rmCollapsed; });
    }

    function apply(root) {
        root = root || document;
        wrapTables(root);
        var small = window.innerWidth < SMALL;
        actionCells(root).forEach(function (td) { small ? collapse(td) : expand(td); });
    }

    function start() {
        apply(document);
        var timer;
        window.addEventListener('resize', function () { clearTimeout(timer); timer = setTimeout(function () { apply(document); }, 150); });
        // Tables replaced by the live search (topbar) are handled too.
        var results = document.getElementById('pageResultsContainer');
        if (results && window.MutationObserver) {
            new MutationObserver(function () { apply(results); }).observe(results, { childList: true });
        }
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();