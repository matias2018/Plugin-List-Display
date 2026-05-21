// Print handler — auto-expand <details> on print, restore after
window.matchMedia("print").addEventListener("change", function (evt) {
    if (evt.matches) {
        const elms = document.body.querySelectorAll("details:not([open])");
        for (const e of elms) {
            e.setAttribute("open", "");
            e.dataset.wasclosed = "";
        }
    } else {
        const elms = document.body.querySelectorAll("details[data-wasclosed]");
        for (const e of elms) {
            e.removeAttribute("open");
            delete e.dataset.wasclosed;
        }
    }
});

// PHP compatibility checker
(function () {

    // Per-target Low thresholds: [ [age_limit_months, min_php], ... ] — first match wins.
    var LOW_PATHS = {
        "8.0": [ [18, 7.4], [12, 7.2] ],
        "8.1": [ [18, 7.4], [12, 7.4] ],
        "8.2": [ [18, 8.0], [12, 7.4] ],
        "8.3": [ [18, 8.0], [12, 7.4] ],
        "8.4": [ [18, 8.1], [12, 8.0] ]
    };

    function init() {
        const btn    = document.getElementById("mi-check-compat");
        const select = document.getElementById("mi-target-php");
        if (!btn || typeof modulesInsight === "undefined") return;

        if (select) {
            select.addEventListener("change", function () {
                syncTarget(select.value);
            });
        }

        btn.addEventListener("click", async function () {
            const targetPhp = select ? select.value : "8.3";

            btn.disabled = true;

            const header   = document.getElementById("mi-risk-col-header");
            const table    = document.getElementById("mi-compat-table");
            const progress = document.getElementById("mi-compat-progress");
            const rows     = Array.from(table.querySelectorAll("tbody tr[data-slug]"));

            if (header) header.textContent = "Risk for PHP " + targetPhp;
            table.style.display    = "";
            progress.style.display = "";

            let done = 0;

            for (const row of rows) {
                const slug = row.dataset.slug;
                const name = row.cells[0].textContent.trim();

                progress.textContent = "Checking " + (done + 1) + " / " + rows.length + " — " + name;
                setRowPending(row);

                try {
                    const body = new FormData();
                    body.append("action", "modules_insight_check_compat");
                    body.append("nonce",  modulesInsight.nonce);
                    body.append("slug",   slug);

                    const res  = await fetch(modulesInsight.ajaxUrl, { method: "POST", body: body });
                    const json = await res.json();

                    if (json.success) {
                        applyResult(row, json.data, targetPhp);
                    } else {
                        setRowError(row);
                    }
                } catch (_) {
                    setRowError(row);
                }

                done++;
                await new Promise(function (r) { setTimeout(r, 150); });
            }

            progress.textContent = "Done — " + rows.length + " plugins checked.";
            btn.textContent      = "Re-check Compatibility";
            btn.disabled         = false;
        });
    }

    // Updates button label, risk column header, and hidden form inputs when target changes.
    function syncTarget(targetPhp) {
        const btn    = document.getElementById("mi-check-compat");
        const header = document.getElementById("mi-risk-col-header");
        const table  = document.getElementById("mi-compat-table");
        const prog   = document.getElementById("mi-compat-progress");

        if (btn) {
            btn.textContent = "Check PHP " + targetPhp + " Compatibility (" + btn.dataset.count + " plugins)";
            btn.disabled    = false;
        }
        if (header) header.textContent = "Risk for PHP " + targetPhp;

        // Reset table rows so stale results from a different target are not shown.
        if (table && table.style.display !== "none") {
            table.querySelectorAll("tbody tr").forEach(function (row) { setRowReset(row); });
        }
        if (prog) { prog.style.display = "none"; prog.textContent = ""; }

        document.querySelectorAll(".mi-target-php-input").forEach(function (el) {
            el.value = targetPhp;
        });
    }

    function setRowPending(row) {
        row.querySelector(".mi-last-updated").textContent  = "…";
        row.querySelector(".mi-tested-up-to").textContent  = "…";
        row.querySelector(".mi-requires-php").textContent  = "…";
        row.querySelector(".mi-risk").textContent          = "…";
        row.className = "";
    }

    function setRowReset(row) {
        row.querySelector(".mi-last-updated").textContent  = "—";
        row.querySelector(".mi-tested-up-to").textContent  = "—";
        row.querySelector(".mi-requires-php").textContent  = "—";
        row.querySelector(".mi-risk").textContent          = "—";
        row.className = "";
    }

    function setRowError(row) {
        row.querySelector(".mi-last-updated").textContent = "Error";
        row.querySelector(".mi-tested-up-to").textContent = "—";
        row.querySelector(".mi-requires-php").textContent = "—";
        const cell = row.querySelector(".mi-risk");
        cell.textContent = "—";
        cell.className   = "mi-risk";
    }

    function applyResult(row, data, targetPhp) {
        row.querySelector(".mi-last-updated").textContent = data.last_updated || "—";
        row.querySelector(".mi-tested-up-to").textContent = data.tested_up_to
            ? "WP " + data.tested_up_to
            : "—";
        row.querySelector(".mi-requires-php").textContent = data.requires_php
            ? "PHP " + data.requires_php
            : "Not set";

        const risk     = calcRisk(data, targetPhp);
        const riskCell = row.querySelector(".mi-risk");
        riskCell.textContent = risk.label;
        riskCell.className   = "mi-risk mi-risk-" + risk.level;
        row.className        = "mi-row-" + risk.level;
    }

    function wpVersionToPhp(wpVer) {
        var v = parseFloat(wpVer) || 0;
        if (v >= 7.0) return 8.0;
        if (v >= 6.3) return 7.4;
        if (v >= 6.0) return 7.0;
        return 5.6;
    }

    function calcRisk(data, targetPhp) {
        if (data.not_found) {
            return { level: "manual", label: "Not on WP.org" };
        }

        var ageMonths = data.last_updated
            ? (Date.now() - new Date(data.last_updated).getTime()) / (1000 * 60 * 60 * 24 * 30.44)
            : 999;
        var minPhp = parseFloat(data.requires_php) || 0;
        var paths  = LOW_PATHS[targetPhp] || LOW_PATHS["8.3"];

        var risk;
        if (ageMonths > 36 || (minPhp > 0 && minPhp < 7.0)) {
            risk = "high";
        } else {
            risk = "medium";
            for (var i = 0; i < paths.length; i++) {
                if (ageMonths <= paths[i][0] && minPhp > 0 && minPhp >= paths[i][1]) {
                    risk = "low";
                    break;
                }
            }
        }

        // Soften age-driven High → Medium when "Tested up to" implies recent PHP testing.
        // Does not apply when High is caused by a declared PHP floor below 7.0.
        if (risk === "high" && (minPhp >= 7.0 || minPhp === 0)) {
            if (wpVersionToPhp(data.tested_up_to || "") >= 7.4) {
                risk = "medium";
            }
        }

        var labels = { low: "Low", medium: "Medium", high: "High" };
        return { level: risk, label: labels[risk] };
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
}());
