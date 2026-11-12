/**
 * NEW FILE: includes/rm_session.js — 30-minute inactivity timeout for the staff portal (loaded by the sidebar).
 * Activity = typing, clicking, scrolling, touching or using a form (not mere mouse movement), in ANY open tab.
 * While the user works inside a page, the server is told at most every 5 minutes, so a long form is never lost.
 * At 28 minutes: a warning with "Stay signed in". At 30 minutes: the session ends and the login page explains why.
 */
(function () {
    var s = document.currentScript, base = (s && s.getAttribute('data-base')) || '';
    var LIMIT = 30 * 60 * 1000, WARN = 28 * 60 * 1000, PING_EVERY = 5 * 60 * 1000, KEY = 'rm_last_activity';
    var lastPing = Date.now(), warned = false, box = null, ending = false;
    function now() { return Date.now(); }
    function last() { var v = 0; try { v = parseInt(localStorage.getItem(KEY) || '0', 10); } catch (e) {} return Math.max(v || 0, window.__rmLast || 0); }
    function mark() { window.__rmLast = now(); try { localStorage.setItem(KEY, String(window.__rmLast)); } catch (e) {} }
    function ping(touch) {
        lastPing = now();
        return fetch(base + '/modules/session/ping.php?touch=' + (touch ? 1 : 0), { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (r) { if (r.status === 401) { expire(); return null; } return r.json(); }).catch(function () { return null; });
    }
    function expire() { if (ending) { return; } ending = true; window.location.href = base + '/modules/session/expired.php'; }
    function hideWarning() { if (box) { box.remove(); box = null; } warned = false; }
    function showWarning(msLeft) {
        warned = true; // FIRST: moving the focus below fires events that must not count as activity
        if (!box) {
            box = document.createElement('div');
            box.setAttribute('role', 'alertdialog'); box.setAttribute('aria-live', 'assertive');
            box.style.cssText = 'position:fixed;inset:0;background:rgba(11,26,140,.45);z-index:3000;display:flex;align-items:center;justify-content:center;padding:16px;';
            box.innerHTML = '<div style="background:#fff;border-radius:14px;max-width:420px;width:100%;padding:22px;box-shadow:0 12px 40px rgba(0,0,0,.25);font-family:inherit;">'
                + '<div style="font-weight:800;font-size:1.1rem;color:#0B1A8C;margin-bottom:6px;">Are you still there?</div>'
                + '<div style="color:#1E2333;">For your security, you will be signed out in <strong id="rmSessLeft">2:00</strong> because of inactivity.</div>'
                + '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;"><button type="button" id="rmSessOut" style="border:1px solid #D8DCE8;background:#fff;border-radius:8px;padding:7px 14px;">Sign out</button>'
                + '<button type="button" id="rmSessStay" style="border:0;background:#1E2FE0;color:#fff;border-radius:8px;padding:7px 14px;font-weight:600;">Stay signed in</button></div></div>';
            document.body.appendChild(box);
            box.querySelector('#rmSessStay').addEventListener('click', function () { mark(); ping(true); hideWarning(); });
            box.querySelector('#rmSessOut').addEventListener('click', expire);
            box.querySelector('#rmSessStay').focus();
        }
        var sec = Math.max(0, Math.ceil(msLeft / 1000)), el = box.querySelector('#rmSessLeft');
        if (el) { el.textContent = Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0'); }
        warned = true;
    }
    function activity(e) {
        if (warned || (e && e.isTrusted === false)) { return; } // during the warning, only "Stay signed in" keeps the session; only real user actions count
        mark();
        if (now() - lastPing > PING_EVERY) { ping(true); }
    }
    ['keydown', 'click', 'scroll', 'touchstart', 'input'].forEach(function (ev) { window.addEventListener(ev, activity, { passive: true, capture: true }); });
    window.addEventListener('storage', function (e) { if (e.key === KEY && warned && now() - last() < WARN) { hideWarning(); } });
    mark(); // opening a page is activity (the server counted it too)
    setInterval(function () {
        var idle = now() - last();
        if (idle >= LIMIT) {
            // Last check with the server before ending (another tab may have kept the session alive).
            ping(false).then(function (r) { if (r && r.ok && r.remaining > 60) { window.__rmLast = now() - (r.limit - r.remaining) * 1000; hideWarning(); } else { expire(); } });
        } else if (idle >= WARN) { showWarning(LIMIT - idle); }
        else if (warned) { hideWarning(); }
    }, 1000);
})();