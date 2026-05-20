# Modules Insight

**Audit installed plugins, assess PHP upgrade risk, and export full reports — all from a single on-demand scan.**

Modules Insight (MI) is a lightweight WordPress plugin built for developers and site managers who need a clear picture of what is installed, and whether it is safe to upgrade PHP.

---

## PHP Upgrade Risk Evaluator

Planning a server PHP upgrade? After running a scan, press **Check PHP 8.3 Compatibility** and MI queries the WordPress.org API for each installed plugin. The result is a colour-coded risk table:

| Risk | Meaning |
|---|---|
| **Low** | Updated within 12–18 months and declares PHP 7.4+ as its minimum. Likely compatible. |
| **Medium** | Falls between Low and High. Test on staging before upgrading production. |
| **High** | Not updated in over 3 years, or declares a minimum PHP below 7.0. Investigate before upgrading. |
| **Not on WP.org** | Not found in the WordPress.org directory (premium or custom plugins). Verify manually with the vendor. |

### How risk is calculated

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

### Important caveats

- Risk ratings are based on **metadata, not code analysis**. A Low-rated plugin could still have incompatibilities; a High-rated plugin might work perfectly.
- The `Requires PHP` field is **self-declared** by plugin authors and is often set conservatively or not updated. A plugin showing Medium may simply have an outdated declaration.
- **Always test on a staging environment** before upgrading PHP on a live server.
- Results from the WordPress.org API are cached per plugin for 24 hours.

### Why is my recently-updated plugin showing Medium?

The most common reason: its `Requires PHP` value on WordPress.org is below 7.4, even if the plugin runs fine on modern PHP. Authors often set this conservatively and forget to raise it. Check the plugin's own changelog for PHP 8.x notes, or test directly on staging.

---

## Features

- **PHP upgrade risk evaluation** via the WordPress.org API — colour-coded Low / Medium / High / Not on WP.org
- **Complete plugin list** — active, inactive, and network-active (multisite), with name, version, author, and URIs
- **Site environment** — active WordPress version and active theme
- **Export as JSON or CSV** — both formats include PHP compatibility data when a check has been run
- **Dashboard widget** and `[plugin_list]` shortcode
- **Scan-on-demand** — nothing runs automatically on page load
- **100% read-only** — safe for production use

---

## How to use

1. Install and activate the plugin.
2. Go to your WordPress **Dashboard** or add the `[plugin_list]` shortcode to any page.
3. Press **Scan Plugins** to load the plugin list.
4. Press **Check PHP 8.3 Compatibility** to query the WordPress.org API and fill the risk table.
5. Use **Download List as JSON** or **Download List as CSV** to export — the compat data is included.

---

## Installation

1. Download or clone this repository.
2. Upload the `modules-insight` folder to `/wp-content/plugins/`.
3. Activate via **Plugins > Installed Plugins** in WordPress.

Or install directly from the [WordPress.org plugin directory](https://wordpress.org/plugins/modules-insight/).

---

## Requirements

- WordPress 5.2 or higher
- PHP 7.2 or higher

---

## License

Distributed under the [GPL 2.0+](https://www.gnu.org/licenses/old-licenses/gpl-2.0.en.html) licence.
