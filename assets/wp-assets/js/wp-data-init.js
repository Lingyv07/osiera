/**
 * Admin "Starter data" page: import, progress and address check.
 *
 * Every string comes from window.tpiDataInitCfg.i18n (rendered by tpi_admin_ui),
 * so this file carries no visible copy of its own.
 */
(function () {
    'use strict';

    var cfg = window.tpiDataInitCfg;
    if (!cfg) { return; }

    var importBtn = document.querySelector('[data-di-import]');
    var probeBtn = document.querySelector('[data-di-probe]');
    var progress = document.querySelector('[data-di-progress]');
    var bar = document.querySelector('[data-di-bar]');
    var stageEl = document.querySelector('[data-di-stage]');
    var resultBox = document.querySelector('[data-di-result]');
    var probeOut = document.querySelector('[data-di-probe-result]');

    /* ---------- Progress: no real stage events, so advance + rotate stage copy ---------- */
    var timer = null;
    var stageTimer = null;
    var pct = 0;

    function startProgress() {
        if (!progress) { return; }
        pct = 6;
        progress.hidden = false;
        if (resultBox) { resultBox.hidden = true; }
        setBar(pct);
        if (stageEl) { stageEl.textContent = cfg.i18n.importing; }

        // Nudge every 400ms, capped at 92%: keep 8% for the real completion
        timer = window.setInterval(function () {
            if (pct >= 92) { return; }
            pct += Math.max(1, Math.round((92 - pct) * 0.06));
            setBar(Math.min(92, pct));
        }, 400);

        var stages = cfg.i18n.stages || [];
        var idx = 0;
        if (stages.length) {
            stageTimer = window.setInterval(function () {
                idx = (idx + 1) % stages.length;
                if (stageEl) { stageEl.textContent = stages[idx]; }
            }, 2600);
        }
    }

    function setBar(v) {
        if (bar) { bar.style.width = v + '%'; }
    }

    function stopProgress(ok) {
        window.clearInterval(timer);
        window.clearInterval(stageTimer);
        timer = null;
        stageTimer = null;
        if (ok) {
            setBar(100);
            if (stageEl) { stageEl.textContent = cfg.i18n.done; }
            window.setTimeout(function () {
                if (progress) { progress.hidden = true; }
            }, 1200);
        } else if (progress) {
            progress.hidden = true;
        }
    }

    /* ---------- Result rendering ---------- */
    function renderResult(ok, message, stats) {
        if (!resultBox) { return; }
        resultBox.hidden = false;
        resultBox.className = 'di-result ' + (ok ? 'is-ok' : 'is-bad');
        resultBox.textContent = '';

        var title = document.createElement('strong');
        title.textContent = message;
        resultBox.appendChild(title);

        if (ok && stats && stats.length) {
            var table = document.createElement('table');
            table.className = 'di-table';
            var tbody = document.createElement('tbody');
            stats.forEach(function (row) {
                var tr = document.createElement('tr');
                var th = document.createElement('th');
                th.textContent = row.label;
                var td = document.createElement('td');
                td.textContent = row.value;
                tr.appendChild(th);
                tr.appendChild(td);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            resultBox.appendChild(table);
        }

        if (ok) {
            var reload = document.createElement('p');
            reload.className = 'ad-hint';
            reload.textContent = cfg.i18n.reload;
            resultBox.appendChild(reload);
        }
    }

    function post(action, extra) {
        var body = new window.URLSearchParams();
        body.append('action', action);
        body.append('nonce', cfg.nonce);
        Object.keys(extra || {}).forEach(function (k) {
            body.append(k, extra[k]);
        });
        return window.fetch(cfg.ajax, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (res) {
            return res.json().catch(function () {
                throw new Error(cfg.i18n.network);
            });
        });
    }

    /* ---------- Import ---------- */
    if (importBtn) {
        importBtn.addEventListener('click', function () {
            if (importBtn.disabled) { return; }
            importBtn.disabled = true;
            startProgress();

            post('tpi_data_init_import').then(function (json) {
                var ok = !!(json && json.success);
                var data = (json && json.data) || {};
                stopProgress(ok);
                renderResult(
                    ok,
                    ok ? cfg.i18n.done : (data.message || cfg.i18n.failed),
                    ok ? (data.stats || []) : null
                );
                if (!ok) { importBtn.disabled = false; }
            }).catch(function (err) {
                stopProgress(false);
                renderResult(false, err && err.message ? err.message : cfg.i18n.network, null);
                importBtn.disabled = false;
            });
        });
    }

    /* ---------- Address check ---------- */
    if (probeBtn) {
        probeBtn.addEventListener('click', function () {
            var old = probeBtn.textContent;
            probeBtn.disabled = true;
            if (probeOut) {
                probeOut.textContent = cfg.i18n.checking;
                probeOut.className = 'di-probe-result';
            }
            post('tpi_data_init_probe').then(function (json) {
                var ok = !!(json && json.success);
                var data = (json && json.data) || {};
                if (probeOut) {
                    probeOut.textContent = data.message || (ok ? cfg.i18n['probe-ok'] : cfg.i18n['probe-fail']);
                    probeOut.className = 'di-probe-result ' + (ok ? 'is-ok' : 'is-bad');
                }
            }).catch(function (err) {
                if (probeOut) {
                    probeOut.textContent = err && err.message ? err.message : cfg.i18n.network;
                    probeOut.className = 'di-probe-result is-bad';
                }
            }).then(function () {
                probeBtn.disabled = false;
                probeBtn.textContent = old;
            });
        });
    }
})();
