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
    function init() {
        const btn = document.getElementById("mi-check-compat");
        if (!btn || typeof modulesInsight === "undefined") return;

        btn.addEventListener("click", async function () {
            btn.disabled = true;

            const table    = document.getElementById("mi-compat-table");
            const progress = document.getElementById("mi-compat-progress");
            const rows     = Array.from(table.querySelectorAll("tbody tr[data-slug]"));

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
                        applyResult(row, json.data);
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

    function setRowPending(row) {
        row.querySelector(".mi-last-updated").textContent = "…";
        row.querySelector(".mi-requires-php").textContent = "…";
        row.querySelector(".mi-risk").textContent         = "…";
        row.className = "";
    }

    function setRowError(row) {
        row.querySelector(".mi-last-updated").textContent = "Error";
        row.querySelector(".mi-requires-php").textContent = "—";
        const cell = row.querySelector(".mi-risk");
        cell.textContent = "—";
        cell.className   = "mi-risk";
    }

    function applyResult(row, data) {
        row.querySelector(".mi-last-updated").textContent = data.last_updated || "—";
        row.querySelector(".mi-requires-php").textContent = data.requires_php
            ? "PHP " + data.requires_php
            : "Not set";

        const risk     = calcRisk(data);
        const riskCell = row.querySelector(".mi-risk");
        riskCell.textContent = risk.label;
        riskCell.className   = "mi-risk mi-risk-" + risk.level;
        row.className        = "mi-row-" + risk.level;
    }

    function calcRisk(data) {
        if (data.not_found) {
            return { level: "manual", label: "Not on WP.org" };
        }

        var ageMonths = data.last_updated
            ? (Date.now() - new Date(data.last_updated).getTime()) / (1000 * 60 * 60 * 24 * 30.44)
            : 999;
        var minPhp = parseFloat(data.requires_php) || 0;

        if (ageMonths > 36 || (minPhp > 0 && minPhp < 7.0)) {
            return { level: "high", label: "High" };
        }
        if ((ageMonths <= 18 && minPhp >= 8.0) || (ageMonths <= 12 && minPhp >= 7.4)) {
            return { level: "low", label: "Low" };
        }
        return { level: "medium", label: "Medium" };
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
}());
