/**
 * NEW FILE: includes/rm_clock.js — the OFFICIAL MY MOTIVE clock (Work Timetable, section 5), loaded by the sidebar.
 *  - The time comes from the SERVER (Africa/Kigali, UTC+2), never from the device: the page is given the server time
 *    when it is built, and the clock then counts with the browser's monotonic timer (performance.now()), which does
 *    not change when someone changes the device's clock. Every minute it re-synchronises with the server.
 *  - Every element with data-rm-clock shows the time; data-rm-countdown="<server ms>" shows the time left until then.
 *  - The same once-a-minute call lets the server send the Work Timetable reminders that are due.
 *  - window.rmServerNow() gives the official time (ms) to other scripts.
 */
(function () {
    var s = document.currentScript;
    var base = (s && s.getAttribute('data-base')) || '';
    var serverAtLoad = parseInt((s && s.getAttribute('data-server-ms')) || '0', 10) || Date.now();
    var perfAtLoad = performance.now();
    var TZ = 'Africa/Kigali';
    var fmtTime, fmtDate;
    try {
        fmtTime = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        fmtDate = new Intl.DateTimeFormat('en-GB', { timeZone: TZ, weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });
    } catch (e) { fmtTime = null; }

    window.rmServerNow = function () { return serverAtLoad + (performance.now() - perfAtLoad); };

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function showTime(ms) {
        if (fmtTime) { return fmtTime.format(new Date(ms)); }
        var d = new Date(ms + 2 * 3600 * 1000); // UTC+2 without Intl
        return pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
    }
    function showDate(ms) { return fmtDate ? fmtDate.format(new Date(ms)) : ''; }
    function showLeft(ms) {
        var neg = ms < 0, t = Math.floor(Math.abs(ms) / 1000);
        var h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), sec = t % 60;
        return (neg ? '-' : '') + (h ? h + ':' + pad(m) : m) + ':' + pad(sec);
    }

    function render() {
        var now = window.rmServerNow();
        var els = document.querySelectorAll('[data-rm-clock]');
        for (var i = 0; i < els.length; i++) {
            els[i].textContent = els[i].getAttribute('data-rm-clock') === 'date' ? showDate(now) : showTime(now);
        }
        var cds = document.querySelectorAll('[data-rm-countdown]');
        for (var j = 0; j < cds.length; j++) {
            var left = parseInt(cds[j].getAttribute('data-rm-countdown'), 10) - now;
            cds[j].textContent = showLeft(left);
            cds[j].classList.toggle('text-danger', left < 0);
        }
    }

    var lastFingerprint = null;
    function sync() {
        var t0 = performance.now();
        var wantStatus = document.body && document.body.hasAttribute('data-tt-live') ? '&status=1' : '';
        fetch(base + '/modules/timetable/api.php?touch=0' + wantStatus, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.server_ms) { return; }
                var latency = (performance.now() - t0) / 2;
                serverAtLoad = j.server_ms + latency; perfAtLoad = performance.now();
                if (j.fingerprint) {
                    if (lastFingerprint && lastFingerprint !== j.fingerprint && !document.querySelector('.modal.show, form :focus')) { window.location.reload(); }
                    lastFingerprint = j.fingerprint;
                }
            }).catch(function () {});
    }

    render();
    setInterval(render, 1000);
    setTimeout(sync, 2000);
    setInterval(sync, 60000);
    // A countdown reaching zero on the Work Timetable page: refresh so the new status and buttons show.
    setInterval(function () {
        if (!document.body || !document.body.hasAttribute('data-tt-live')) { return; }
        var cds = document.querySelectorAll('[data-rm-countdown][data-refresh-at-zero]'), now = window.rmServerNow();
        for (var i = 0; i < cds.length; i++) {
            var at = parseInt(cds[i].getAttribute('data-rm-countdown'), 10);
            if (now >= at && now - at < 5000 && !document.querySelector('.modal.show, form :focus')) { window.location.reload(); return; }
        }
    }, 2500);
})();
