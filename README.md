# Modules Insight

**Audit installed plugins, assess PHP _and_ WordPress upgrade risk, and export full reports — all from a single on-demand scan.**

Modules Insight (MI) is a lightweight WordPress plugin built for developers and site managers who need a clear picture of what is installed, and whether it is safe to upgrade PHP or WordPress.

---

## Upgrade Risk Evaluator

Planning a server PHP upgrade, a WordPress major, or both? After running a scan, choose your **Target PHP** (8.0–8.5) and **Target WP** (6.7–7.1) and press **Check Upgrade Compatibility**. MI queries the WordPress.org API once per plugin and fills two colour-coded risk columns.

### PHP risk

| Risk | Meaning |
|---|---|
| **Low** | Updated within 12–18 months and declares a recent PHP (7.4–8.2, depending on target) as its minimum. Likely compatible. |
| **Medium** | Falls between Low and High. Test on staging before upgrading production. |
| **High** | Not updated in over 3 years, or declares a minimum PHP below 7.0. Investigate before upgrading. |
| **Not on WP.org** | Not found in the WordPress.org directory (premium or custom plugins). Verify manually with the vendor. |

### WordPress risk

| Risk | Meaning |
|---|---|
| **Low** | "Tested up to" is at or beyond your target — or one release behind and updated within 18 months. |
| **Medium** | A release or two behind, or behind and getting stale. Test on staging. |
| **High** | Five or more releases behind your target, or not updated in over 3 years. Investigate before upgrading. |
| **Unknown** | The plugin declares no "Tested up to" value — treat like Not on WP.org. |
| **Not on WP.org** | Not found in the directory. Verify manually with the vendor. |

Both columns are metadata signals, not code analysis. The row is tinted by whichever of the two risks is worse.

### How PHP risk is calculated

Risk is derived from two signals pulled from the WordPress.org plugin directory:

**Signal 1 — Last Updated:** how recently the plugin received a published release.

**Signal 2 — Minimum PHP Declared:** the `Requires PHP` field the plugin author set on WordPress.org.

The decision logic:

```
If not found on WordPress.org          → Not on WP.org (manual check)
If age > 36 months OR min PHP < 7.0   → High
If age ≤ 18 months AND min PHP ≥ 8.0  → Low
If age ≤ 12 months AND min PHP ≥ 7.4  → Low
Otherwise                              → Medium
```

A deeper view:
```
Signal 1 — Age (months since last update on WP.org)


age_months = (now - last_updated_date) / 30.44
If last_updated is missing, age defaults to 999 (treated as ancient).

Signal 2 — Declared minimum PHP


requires_php = float value from WP.org (e.g. 7.4, 8.0)
If not set, it defaults to 0.

The decision tree — executed top to bottom, first match wins:


1. Plugin not found on WP.org?
   → "Not on WP.org"  (stop)

2. age > 36 months  OR  (requires_php > 0  AND  requires_php < 7.0)?
   → High  (stop)

3. (age ≤ 18  AND  requires_php ≥ 8.0)
   OR
   (age ≤ 12  AND  requires_php ≥ 7.4)?
   → Low  (stop)

4. Everything else
   → Medium
What this means in plain language:

High fires on either signal alone — an old plugin (3+ years) OR a very low declared minimum (below PHP 7.0). Only one condition needs to be true.
Low requires both signals to be good simultaneously. Two sub-paths exist:
Updated within 18 months AND declares PHP 8.0+ (the strict path)
Updated within 12 months AND declares PHP 7.4+ (the looser path, but requires fresher age)
Medium is the catch-all for everything that isn't clearly good or clearly bad.
The key consequence you noticed earlier:

A plugin updated last week (age ≈ 0.03 months) but with requires_php: 7.2 hits rule 3 as:

age ≤ 12 ✓ but requires_php ≥ 7.4 ✗
age ≤ 18 ✓ but requires_php ≥ 8.0 ✗
Neither Low path passes → falls through to Medium, despite being brand new. That is why this plugin rates itself Medium — its own Requires PHP: 7.2 header is below both Low thresholds.
```

### Important caveats

- Risk ratings are based on **metadata, not code analysis**. A Low-rated plugin could still have incompatibilities; a High-rated plugin might work perfectly.
- The `Requires PHP` field is **self-declared** by plugin authors and is often set conservatively or not updated. A plugin showing Medium may simply have an outdated declaration.
- **Always test on a staging environment** before upgrading PHP on a live server.
- Results from the WordPress.org API are cached per plugin for 24 hours.

### Why is my recently-updated plugin showing Medium?

The most common reason: its `Requires PHP` value on WordPress.org is below 7.4, even if the plugin runs fine on modern PHP. Authors often set this conservatively and forget to raise it. Check the plugin's own changelog for PHP 8.x notes, or test directly on staging.

---

## Features

- **PHP + WordPress upgrade risk** via the WordPress.org API — two colour-coded columns, selectable targets (PHP 8.0–8.5, WP 6.7–7.1)
- **Complete plugin list** — active, inactive, and network-active (multisite), with name, version, author, and URIs
- **Site environment** — active WordPress version and active theme
- **Export as JSON, CSV, or Google Sheet** — all include the compatibility data when a check has been run
- **Ask AI** — question a specific plugin's compatibility via the WordPress 7.0 core AI Client (optional; hides itself when unavailable)
- **Dashboard widget** and `[plugin_list]` shortcode
- **Scan-on-demand** — nothing runs automatically on page load
- **No writes to your site** — the only outbound writes are the report rows you send to your own Google Sheet

---

## How to use

1. Install and activate the plugin.
2. Go to your WordPress **Dashboard** or add the `[plugin_list]` shortcode to any page.
3. Press **Scan Plugins** to load the plugin list.
4. Choose **Target PHP** and **Target WP**, then press **Check Upgrade Compatibility** to query the WordPress.org API and fill both risk columns.
5. Export with **Download List as JSON / CSV**, or **Send report to Google Sheet** once configured.
6. On rows rated Medium / High / Not on WP.org, use **Ask AI** for a written second opinion (needs WordPress 7.0+ with a provider under Settings → AI).

### Google Sheets export

1. Open a Google Sheet → **Extensions → Apps Script**.
2. Paste the script shown on **Settings → Modules Insight** and set your own `SECRET`.
3. **Deploy → New deployment → Web app**, execute as *Me*, access *Anyone with the link*.
4. Copy the `/exec` URL and the `SECRET` into the plugin's settings page.

---

## Installation

1. Download or clone this repository.
2. Upload the `modules-insight` folder to `/wp-content/plugins/`.
3. Activate via **Plugins > Installed Plugins** in WordPress.

Or install directly from the [WordPress.org plugin directory](https://wordpress.org/plugins/modules-insight/).

---

## Requirements

- WordPress 6.0 or higher (7.0+ for the optional "Ask AI" feature)
- PHP 8.0 or higher

---

## License

Distributed under the [GPL 2.0+](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html) licence.
