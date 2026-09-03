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

// Upgrade compatibility checker — PHP + WordPress risk
(function () {

    // Per-target Low thresholds: [ [age_limit_months, min_php], ... ] — first match wins.
    var LOW_PATHS = {
        "8.0": [ [18, 7.4], [12, 7.2] ],
        "8.1": [ [18, 7.4], [12, 7.4] ],
        "8.2": [ [18, 8.0], [12, 7.4] ],
        "8.3": [ [18, 8.0], [12, 7.4] ],
        "8.4": [ [18, 8.1], [12, 8.0] ],
        "8.5": [ [18, 8.2], [12, 8.1] ]
    };

    var RISK_LABELS = { low: "Low", medium: "Medium", high: "High", manual: "Not on WP.org", unknown: "Unknown" };
    var RISK_RANK   = { low: 1, unknown: 2, manual: 2, medium: 3, high: 4 };
    var AI_LEVELS   = { medium: 1, high: 1, unknown: 1, manual: 1 };

    function $(id) { return document.getElementById(id); }

    function format(str, args) {
        return str.replace(/%(\d+)\$s/g, function (_, n) { return args[n - 1]; });
    }

    function init() {
        const btn    = $("mi-check-compat");
        const selPhp = $("mi-target-php");
        const selWp  = $("mi-target-wp");
        if (!btn || typeof modulesInsight === "undefined") return;

        if (selPhp) selPhp.addEventListener("change", syncTargets);
        if (selWp)  selWp.addEventListener("change", syncTargets);

        btn.addEventListener("click", runCheck);

        initGsheet();
        initAi();
    }

    function currentTargets() {
        const selPhp = $("mi-target-php");
        const selWp  = $("mi-target-wp");
        return {
            php: selPhp ? selPhp.value : "8.3",
            wp:  selWp ? selWp.value : "7.1"
        };
    }

    async function runCheck() {
        const btn = $("mi-check-compat");
        const t   = currentTargets();

        btn.disabled = true;

        const phpHeader = $("mi-risk-col-header");
        const wpHeader  = $("mi-wp-risk-col-header");
        const table     = $("mi-compat-table");
        const progress  = $("mi-compat-progress");
        const rows      = Array.from(table.querySelectorAll("tbody tr[data-slug]"));

        if (phpHeader) phpHeader.textContent = "Risk for PHP " + t.php;
        if (wpHeader)  wpHeader.textContent = "Risk for WP " + t.wp;
        table.style.display    = "";
        progress.style.display = "";

        let done = 0;

        for (const row of rows) {
            const slug = row.dataset.slug;
            const name = row.dataset.name || row.cells[0].textContent.trim();

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
                    applyResult(row, json.data, t);
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
        btn.textContent      = "Re-check compatibility";
        btn.disabled         = false;
    }

    // Updates headers, button label and hidden form inputs when a target changes,
    // and resets the table so stale results for a different target are not shown.
    function syncTargets() {
        const t      = currentTargets();
        const btn    = $("mi-check-compat");
        const table  = $("mi-compat-table");
        const prog   = $("mi-compat-progress");

        if (btn) {
            btn.textContent = "Check Upgrade Compatibility (" + btn.dataset.count + " plugins)";
            btn.disabled    = false;
        }
        const phpHeader = $("mi-risk-col-header");
        const wpHeader  = $("mi-wp-risk-col-header");
        if (phpHeader) phpHeader.textContent = "Risk for PHP " + t.php;
        if (wpHeader)  wpHeader.textContent = "Risk for WP " + t.wp;

        if (table && table.style.display !== "none") {
            table.querySelectorAll("tbody tr[data-slug]").forEach(setRowReset);
        }
        if (prog) { prog.style.display = "none"; prog.textContent = ""; }

        document.querySelectorAll(".mi-target-php-input").forEach(function (el) { el.value = t.php; });
        document.querySelectorAll(".mi-target-wp-input").forEach(function (el) { el.value = t.wp; });
        const push = $("mi-push-gsheet");
        if (push) { push.dataset.php = t.php; push.dataset.wp = t.wp; }

        closeAiPanel();
    }

    function riskCells(row) {
        return {
            updated: row.querySelector(".mi-last-updated"),
            tested:  row.querySelector(".mi-tested-up-to"),
            php:     row.querySelector(".mi-requires-php"),
            risk:    row.querySelector(".mi-risk"),
            wpRisk:  row.querySelector(".mi-wp-risk"),
            ai:      row.querySelector(".mi-ai-cell")
        };
    }

    function setRowPending(row) {
        const c = riskCells(row);
        c.updated.textContent = "…";
        c.tested.textContent  = "…";
        c.php.textContent     = "…";
        c.risk.textContent    = "…";
        c.wpRisk.textContent  = "…";
        c.risk.className      = "mi-risk";
        c.wpRisk.className     = "mi-risk";
        if (c.ai) c.ai.textContent = "";
        row.className = "";
    }

    function setRowReset(row) {
        const c = riskCells(row);
        c.updated.textContent = "—";
        c.tested.textContent  = "—";
        c.php.textContent     = "—";
        c.risk.textContent    = "—";
        c.wpRisk.textContent  = "—";
        c.risk.className      = "mi-risk";
        c.wpRisk.className     = "mi-risk";
        if (c.ai) c.ai.textContent = "—";
        row.className = "";
    }

    function setRowError(row) {
        const c = riskCells(row);
        c.updated.textContent = "Error";
        c.tested.textContent  = "—";
        c.php.textContent     = "—";
        c.risk.textContent    = "—";
        c.wpRisk.textContent  = "—";
        c.risk.className      = "mi-risk";
        c.wpRisk.className     = "mi-risk";
        if (c.ai) c.ai.textContent = "—";
        row.className = "";
    }

    function applyResult(row, data, t) {
        const c = riskCells(row);
        c.updated.textContent = data.last_updated || "—";
        c.tested.textContent  = data.tested_up_to ? "WP " + data.tested_up_to : "—";
        c.php.textContent     = data.requires_php ? "PHP " + data.requires_php : "Not set";

        const php = calcRisk(data, t.php);
        const wp  = calcWpRisk(data, t.wp);

        c.risk.textContent = php.label;
        c.risk.className   = "mi-risk mi-risk-" + php.level;
        c.wpRisk.textContent = wp.label;
        c.wpRisk.className   = "mi-risk mi-risk-" + wp.level;

        const worst = RISK_RANK[php.level] >= RISK_RANK[wp.level] ? php.level : wp.level;
        row.className = "mi-row-" + worst;

        if (c.ai) {
            if (modulesInsight.aiAvailable && (AI_LEVELS[php.level] || AI_LEVELS[wp.level])) {
                c.ai.innerHTML = "";
                const b = document.createElement("button");
                b.type = "button";
                b.className = "button-link mi-ask-ai";
                b.textContent = "Ask AI";
                c.ai.appendChild(b);
            } else {
                c.ai.textContent = "—";
            }
        }
    }

    function wpVersionToPhp(wpVer) {
        var v = parseFloat(wpVer) || 0;
        if (v >= 7.1) return 8.2;
        if (v >= 7.0) return 8.0;
        if (v >= 6.3) return 7.4;
        if (v >= 6.0) return 7.0;
        return 5.6;
    }

    // "major.minor" -> monotonic release ordinal (6.8 -> 68, 7.0 -> 70).
    function wpReleaseOrdinal(ver) {
        var m = /^(\d+)\.(\d+)/.exec(String(ver).trim());
        return m ? parseInt(m[1], 10) * 10 + parseInt(m[2], 10) : 0;
    }

    function ageMonths(lastUpdated) {
        return lastUpdated
            ? (Date.now() - new Date(lastUpdated).getTime()) / (1000 * 60 * 60 * 24 * 30.44)
            : 999;
    }

    function calcRisk(data, targetPhp) {
        if (data.not_found) {
            return { level: "manual", label: RISK_LABELS.manual };
        }

        var age    = ageMonths(data.last_updated);
        var minPhp = parseFloat(data.requires_php) || 0;
        var paths  = LOW_PATHS[targetPhp] || LOW_PATHS["8.3"];

        var risk;
        if (age > 36 || (minPhp > 0 && minPhp < 7.0)) {
            risk = "high";
        } else {
            risk = "medium";
            for (var i = 0; i < paths.length; i++) {
                if (age <= paths[i][0] && minPhp > 0 && minPhp >= paths[i][1]) {
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

        return { level: risk, label: RISK_LABELS[risk] };
    }

    // Mirror of calculate_wp_compat_risk() in modules-insight.php.
    function calcWpRisk(data, targetWp) {
        if (data.not_found) {
            return { level: "manual", label: RISK_LABELS.manual };
        }
        if (!data.tested_up_to) {
            return { level: "unknown", label: RISK_LABELS.unknown };
        }

        var behind = wpReleaseOrdinal(targetWp) - wpReleaseOrdinal(data.tested_up_to);
        var age    = ageMonths(data.last_updated);

        var risk;
        if (behind <= 0) {
            risk = "low";
        } else if (behind === 1 && age <= 18) {
            risk = "low";
        } else if (behind >= 5 || age > 36) {
            risk = "high";
        } else {
            risk = "medium";
        }

        return { level: risk, label: RISK_LABELS[risk] };
    }

    /* ---- Google Sheet push ---- */

    function initGsheet() {
        const push = $("mi-push-gsheet");
        if (!push) return;

        push.addEventListener("click", async function () {
            const status = $("mi-gsheet-status");
            push.disabled = true;
            if (status) status.textContent = "Sending…";

            try {
                const body = new FormData();
                body.append("action", "modules_insight_push_gsheet");
                body.append("nonce", modulesInsight.nonce);
                body.append("target_php", push.dataset.php);
                body.append("target_wp", push.dataset.wp);

                const res  = await fetch(modulesInsight.ajaxUrl, { method: "POST", body: body });
                const json = await res.json();

                if (status) {
                    status.textContent = json.success
                        ? (json.data && json.data.message) || "Sent."
                        : "Failed: " + ((json.data) || "unknown error");
                }
            } catch (_) {
                if (status) status.textContent = "Failed: network error.";
            }

            push.disabled = false;
        });
    }

    /* ---- Ask AI ---- */

    function initAi() {
        const panel = $("mi-ai-panel");
        if (!panel || !modulesInsight.aiAvailable) return;

        document.addEventListener("click", function (evt) {
            const trigger = evt.target.closest(".mi-ask-ai");
            if (!trigger) return;
            const row = trigger.closest("tr[data-slug]");
            if (row) openAiPanel(row);
        });

        const ask   = $("mi-ai-ask");
        const close = $("mi-ai-close");
        if (ask)   ask.addEventListener("click", submitAiQuestion);
        if (close) close.addEventListener("click", function (e) { e.preventDefault(); closeAiPanel(); });
    }

    function openAiPanel(row) {
        const panel = $("mi-ai-panel");
        const t     = currentTargets();
        const name  = row.dataset.name || "";
        const ver   = row.dataset.version || "";

        panel.dataset.slug = row.dataset.slug;
        panel.querySelector(".mi-ai-panel-title").textContent = "Ask AI about: " + name;
        $("mi-ai-question").value = format(modulesInsight.i18n.aiDefaultQuestion, [name, ver, t.php, t.wp]);
        $("mi-ai-answer").textContent = "";
        panel.hidden = false;
        panel.scrollIntoView({ behavior: "smooth", block: "nearest" });
        $("mi-ai-question").focus();
    }

    function closeAiPanel() {
        const panel = $("mi-ai-panel");
        if (panel) panel.hidden = true;
    }

    async function submitAiQuestion() {
        const panel  = $("mi-ai-panel");
        const ask    = $("mi-ai-ask");
        const answer = $("mi-ai-answer");
        const t      = currentTargets();
        const question = $("mi-ai-question").value.trim();

        if (!question) { answer.textContent = "Please enter a question."; return; }

        ask.disabled = true;
        answer.textContent = "Thinking…";

        try {
            const body = new FormData();
            body.append("action", "modules_insight_ai_ask");
            body.append("nonce", modulesInsight.nonce);
            body.append("slug", panel.dataset.slug);
            body.append("question", question);
            body.append("target_php", t.php);
            body.append("target_wp", t.wp);

            const res  = await fetch(modulesInsight.ajaxUrl, { method: "POST", body: body });
            const json = await res.json();

            answer.textContent = json.success
                ? json.data.answer + (json.data.cached ? "\n\n(cached)" : "")
                : "Error: " + ((json.data) || "unknown error");
        } catch (_) {
            answer.textContent = "Error: network failure.";
        }

        ask.disabled = false;
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
}());
