/**
 * NEW FILE: includes/searchable_select.js  (loaded on every page by includes/sidebar.php)
 * Turns every long dropdown (8+ choices) into a type-to-search box: type part of a name and the
 * matching choices drop down. The original <select> stays in the page (hidden) and keeps its value,
 * so forms, "required" checks and page scripts that react to it all work exactly as before.
 *   - Only real options can be chosen (no free text).
 *   - Choosing fires the select's normal "change" event (pages that reload or fill in prices still work).
 *   - Options added or changed later (e.g. Province -> District, new sale rows) are picked up.
 * Opt out:  <select data-no-search>.   Force on a short list:  <select data-searchable>.
 */
(function () {
    'use strict';
    var MIN_OPTIONS = 8;
    var MAX_SHOWN = 50;

    function norm(s) { return (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }

    function wanted(sel) {
        if (sel.dataset.ssDone || sel.multiple || (sel.size && sel.size > 1) || sel.hasAttribute('data-no-search')) { return false; }
        if (sel.closest('[data-no-search]')) { return false; }
        return sel.hasAttribute('data-searchable') || sel.options.length >= MIN_OPTIONS;
    }

    function enhance(sel) {
        if (!wanted(sel)) { return; }
        sel.dataset.ssDone = '1';

        var wrap = document.createElement('div');
        wrap.className = 'ss-wrap';
        wrap.style.position = 'relative';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);

        var input = document.createElement('input');
        input.type = 'text';
        input.autocomplete = 'off';
        input.className = (sel.className || '').replace(/\bform-select\b/g, 'form-control') || 'form-control';
        if (input.className.indexOf('form-control') === -1) { input.className += ' form-control'; }
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        if (sel.id) { input.setAttribute('aria-label', (document.querySelector('label[for="' + sel.id + '"]') || {}).textContent || ''); }
        wrap.insertBefore(input, sel);

        // The real select stays focusable (for "required" messages) but invisible.
        sel.tabIndex = -1;
        sel.setAttribute('aria-hidden', 'true');
        sel.style.cssText += ';position:absolute;left:0;bottom:0;width:100%;height:1px;opacity:0;pointer-events:none;';

        var list = document.createElement('div');
        list.className = 'list-group shadow';
        list.style.cssText = 'position:absolute;left:0;right:0;top:100%;z-index:1080;max-height:280px;overflow-y:auto;display:none;';
        wrap.appendChild(list);

        var shown = [], active = -1;

        function selectedText() {
            var o = sel.options[sel.selectedIndex];
            return o && o.value !== '' ? o.text : '';
        }
        function placeholderText() {
            var o = sel.options[0];
            return o && o.value === '' ? o.text : 'Type to search...';
        }
        function syncFromSelect() {
            input.value = selectedText();
            input.placeholder = placeholderText() + ' (type to search)';
            input.disabled = sel.disabled;
        }
        function close() { list.style.display = 'none'; active = -1; }
        function render() {
            var q = norm(input.value.trim());
            var current = selectedText();
            if (q !== '' && input.value === current) { q = ''; } // just focused a chosen value: show everything
            shown = [];
            for (var i = 0; i < sel.options.length && shown.length < MAX_SHOWN; i++) {
                var o = sel.options[i];
                if (o.disabled || o.hidden) { continue; }
                if (o.value === '' && q !== '') { continue; }
                if (q === '' || norm(o.text).indexOf(q) !== -1) { shown.push(o); }
            }
            list.innerHTML = '';
            shown.forEach(function (o, idx) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'list-group-item list-group-item-action py-2' + (idx === active ? ' active' : '') + (o.selected && o.value !== '' ? ' fw-semibold' : '');
                b.textContent = o.text;
                b.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(o); });
                list.appendChild(b);
            });
            if (!shown.length) {
                var none = document.createElement('div');
                none.className = 'list-group-item text-muted small';
                none.textContent = 'No match for "' + input.value + '"';
                list.appendChild(none);
            }
            list.style.display = '';
            if (active >= 0 && list.children[active] && list.children[active].scrollIntoView) { list.children[active].scrollIntoView({ block: 'nearest' }); }
        }
        function choose(o) {
            var changed = sel.value !== o.value;
            sel.value = o.value;
            input.value = o.value === '' ? '' : o.text;
            close();
            if (changed) {
                sel.dispatchEvent(new Event('input', { bubbles: true }));
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        input.addEventListener('focus', function () { input.select(); render(); });
        input.addEventListener('input', function () { active = -1; render(); });
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown') { ev.preventDefault(); if (list.style.display === 'none') { render(); } active = Math.min(active + 1, shown.length - 1); render(); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (ev.key === 'Enter') {
                if (list.style.display !== 'none' && (shown[active] || shown.length === 1)) { ev.preventDefault(); choose(shown[active] || shown[0]); }
            }
            else if (ev.key === 'Escape') { close(); syncFromSelect(); }
        });
        // Leaving the box: only a real choice is kept; typed text that is not a choice is undone.
        input.addEventListener('blur', function () {
            setTimeout(function () {
                close();
                if (input.value.trim() === '') {
                    if (sel.options[0] && sel.options[0].value === '' && sel.value !== '') { choose(sel.options[0]); }
                    else { syncFromSelect(); }
                } else {
                    syncFromSelect();
                }
            }, 150);
        });

        // Changes made by the page itself (resets, new options, enabling/disabling).
        sel.addEventListener('change', syncFromSelect);
        new MutationObserver(syncFromSelect).observe(sel, { childList: true, subtree: true, attributes: true, attributeFilter: ['disabled'] });
        // A "required" message on the hidden select brings the user to the search box.
        sel.addEventListener('invalid', function () { input.focus(); });
        syncFromSelect();
    }

    function scan(root) {
        (root.querySelectorAll ? root.querySelectorAll('select') : []).forEach(enhance);
    }

    function start() {
        scan(document);
        // Selects added later (new sale rows, pop-ups) and lists that grow past 8 options.
        new MutationObserver(function (muts) {
            muts.forEach(function (m) {
                m.addedNodes.forEach(function (n) {
                    if (n.nodeType !== 1) { return; }
                    if (n.tagName === 'SELECT') { enhance(n); }
                    else if (n.tagName === 'OPTION' && n.parentNode && n.parentNode.tagName === 'SELECT') { enhance(n.parentNode); }
                    else { scan(n); }
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();