=== Modules Insight ===
Contributors: Pedro Matias
Tags: plugin management, plugin report, admin tools, plugin status, developer tools
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 3.1.3
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Audit installed plugins, assess PHP upgrade risk via the WordPress.org API, and export full reports as JSON or CSV.

== Description ==

**Modules Insight** helps WordPress developers and site managers audit installed plugins, assess the risk of upgrading PHP, and export complete reports — all from a single on-demand scan.

=== PHP Upgrade Risk Evaluator ===

Planning a server PHP upgrade? MI queries the **WordPress.org API** for each installed plugin and produces a colour-coded risk table showing how likely each plugin is to break on your target PHP version.

Each plugin is rated **Low**, **Medium**, **High**, or **Not on WP.org** based on two signals:

**1. Last Updated** — how recently the plugin received a release on WordPress.org.
**2. Minimum PHP Declared** — the `Requires PHP` field set by the plugin author.

Risk is assigned as follows:

* **High** — Not updated in over 3 years, or declares a minimum PHP below 7.0. These carry the greatest risk of breaking on PHP 8.x and should be investigated before upgrading.
* **Medium** — Falls between High and Low. Test on a staging environment before upgrading production.
* **Low** — Updated within the last 12–18 months and declares PHP 7.4 or higher as its minimum. Likely compatible, but a quick smoke test after upgrading is still recommended.
* **Not on WP.org** — Not found in the WordPress.org directory (premium plugins, custom code). Compatibility must be verified manually with the vendor.

**Important:** risk ratings are based on publicly available metadata, not code analysis. A Low-rated plugin could still have incompatibilities; a High-rated plugin might work perfectly. Use the table as a triage guide, not a guarantee. Always test on a staging environment before upgrading PHP on a live server.

Results from the WordPress.org API are cached per plugin for 24 hours to avoid unnecessary external requests.

=== Plugin List and Reports ===

MI lists all installed plugins (active, inactive, and network-active on multisite) with version numbers, author details, and descriptions. It also reports the active WordPress version and active theme.

Reports can be exported as `.json` or `.csv`. Both formats include the PHP compatibility data if a check has been run prior to export.

Tested and fully compatible with **WordPress 7.0**.

=== Key Features ===

* PHP upgrade risk evaluation via the WordPress.org API
* Colour-coded risk table: Low / Medium / High / Not on WP.org
* Lists all installed plugins with status, version, author, and URIs
* Reports WordPress version and active theme
* Export as JSON or CSV (includes compat data when available)
* Dashboard widget and `[plugin_list]` shortcode
* Scan-on-demand — nothing runs automatically on page load
* 100% read-only — safe for production use

=== Use Cases ===

* Assessing risk before upgrading PHP on a server
* Managing multiple WordPress sites and keeping plugins audited
* Client-facing reports on installed plugins
* Pre-deployment or pre-update plugin audits

== Installation ==

1. Upload the `modules-insight` folder to the `/wp-content/plugins/` directory, or install through the WordPress plugin screen directly (Plugins > Add New).
2. Activate the plugin through the Plugins menu in WordPress.
3. Check your **Dashboard** for the "Modules Insight - Plugin List" widget, or use the shortcode `[plugin_list]` on any page or post.
4. Press **Scan Plugins** to load the plugin list.
5. Press **Check PHP 8.3 Compatibility** to run the risk evaluation against the WordPress.org API.
6. Once the scan is complete, press **Download List as JSON** or **Download List as CSV** to export the full report, including the compatibility data.

== Frequently Asked Questions ==

= How is the PHP upgrade risk calculated? =

Risk is based on two signals pulled from the WordPress.org plugin directory for each plugin:

**1. Last Updated** — how recently the plugin received a published release.
**2. Minimum PHP Declared** — the `Requires PHP` value the author set on WordPress.org.

The rating is assigned as follows:

* **High** — Not updated in over 3 years, OR declares a minimum PHP below 7.0. Investigate before upgrading.
* **Low** — Updated within 12–18 months AND declares PHP 7.4 or higher as its minimum.
* **Medium** — Anything between High and Low. Test on staging before upgrading production.
* **Not on WP.org** — Plugin not found in the directory. Risk must be assessed manually.

These are metadata signals, not a code scan. Always test on a staging environment before upgrading PHP on a live server.

= Why is my recently-updated plugin showing as Medium instead of Low? =

The most common reason is that the plugin’s `Requires PHP` field on WordPress.org is set below 7.4 — even if the plugin runs perfectly on modern PHP. Authors often set this conservatively and forget to update it. In this case Medium does not mean the plugin is broken; it means the metadata is incomplete. Check the plugin’s own changelog or test directly on staging.

= What does "Not on WP.org" mean in the risk table? =

The plugin was not found in the WordPress.org directory. This is normal for premium plugins (WooCommerce extensions, page builder add-ons, etc.) and custom-built plugins. Their compatibility cannot be assessed automatically — check with the vendor or test directly on a staging environment running the target PHP version.

= Are the risk ratings a guarantee? =

No. They are a triage guide based on publicly available metadata. A Low-rated plugin could still have incompatibilities; a High-rated plugin might work perfectly. The table helps you decide where to focus your testing effort, not whether to skip testing altogether.

= Does this plugin make any changes to my site? =

No. MI is completely read-only. It does not activate, deactivate, install, or delete any plugins.

= Who can see the plugin list and run the compatibility check? =

Only users with the `activate_plugins` capability (typically Administrators).

= What formats can I export? =

JSON and CSV. Both include the PHP compatibility data (last updated, minimum PHP, risk level) for any plugin that has been checked. Plugins not yet checked show `not_checked` in those fields.

= Can I use this on a live/production site? =

Yes. MI performs no write operations and loads no assets unless an admin explicitly presses Scan.

== Screenshots ==

1. The Modules Insight dashboard widget showing active/inactive plugins.
2. Example of a page generated using the shortcode and the collapsed description view using `<details>`.
3. The "Download List as JSON" button available to administrators and structure of the exported JSON file.
4. Example of "print" page using shortcode and automatic expanded description view using `<details>`.

== Changelog ==

= 3.1.3 =
* Fix: Screenshots and plugin icon now correctly deployed to the WordPress.org assets directory.

= 3.1.2 =
* Compat: Tested and confirmed compatible with WordPress 7.0.
* Updated minimum requirements: WordPress 6.0+, PHP 8.0+.
* Updated screenshots and plugin icon.
* Plugin URI updated to https://aura-plugins.com.

= 3.1.1 =
* Docs: Rewrote plugin description and FAQ to lead with the PHP upgrade risk evaluator and document how risk is calculated.

= 3.1.0 =
* Feature: PHP Compatibility Checker — after a scan, a "Check PHP 8.3 Compatibility" button queries the WordPress.org API for each plugin (last updated, minimum PHP required) and displays a colour-coded risk table (Low / Medium / High / Not on WP.org). Results are cached per plugin for 24 hours.
* Feature: JSON and CSV exports now include the cached compatibility data (last updated, min PHP, risk level) for any plugin that has been checked. Exports without a prior check show "not_checked".
* Feature: Plugin assets now load on the admin dashboard as well as the frontend, enabling the compatibility checker inside the dashboard widget.

= 3.0.0 =
* Feature: Plugin data is now loaded on demand — a Scan button must be pressed before any data is retrieved. Nothing runs on page load automatically.
* Perf: Plugin data is cached via transient (5 min) and auto-invalidated on plugin activate/deactivate/update and theme switch.
* Perf: Replaced O(n) in_array() loop with O(1) array_flip()+isset() for active plugin lookups.
* Perf: Cached is_single()||is_page() result before the plugin loop to avoid redundant calls per iteration.
* Perf: Removed redundant wp_kses() pass in the dashboard widget — all output is already escaped at source.
* Fix: Print CSS now correctly hides the download buttons wrapper (was targeting a stale selector).

= 2.9.9 =
* Feature: Report now includes WordPress version and active theme info (name, version, author, URI) in the HTML output and in both JSON and CSV exports.

= 2.9.8 =
* Feature: Added CSV export — administrators can now download the plugin list as a .csv file alongside the existing JSON export.

= 2.9.7 =
* Version bump.

= 2.9.6 =
* Version bump.

= 2.9.5 =
* Version bump.

= 2.9.4 =
* Version bump.

= 2.9.3 =
* Version bump.

= 2.9.2 =
* Fix: Remove duplicate nonce hidden field in the download form (wp_nonce_field() already outputs it).
* Fix: JS print listener now only closes `<details>` elements that were auto-opened, preserving manually-opened ones.
* Fix: Declare `elms` and `e` with `const` in the print media listener to avoid implicit globals.
* Perf: Move `get_site_option('active_sitewide_plugins')` call outside the plugin foreach loop on multisite.
* Security: Simplify shortcode capability check — remove `is_admin()` context check, rely solely on `activate_plugins`.
* Perf: Register assets on `wp_enqueue_scripts` and enqueue them inside the shortcode, so they only load on pages using `[plugin_list]`.
* Perf: Update asset version strings from 2.3.0 to 2.9.2 to ensure browsers pick up current files.
* Accessibility: Replace `outline: none` on button focus with a visible `2px solid` outline.
* Dev: Add `.vscode/settings.json` enabling Intelephense's built-in WordPress stubs for accurate static analysis.

= 2.9.1 =
* Style: Added `fusion-tb-footer` and `fusion-footer` CSS classes to print hide rules for Avada theme compatibility.
* Version bump for CSS and JS assets.

= 2.9.0 =
* Feature: Report header now shows site name, date, and URL when shortcode is rendered on a page or post.

= 2.8.0 =
* Fix: Corrected plugin GitHub URI to https://github.com/matias2018/Plugin-List-Display.

= 2.7.2 =
* Style: Added specific print-hide rules for Avada theme custom footer elements.

= 2.7.0 =
* Feature: Added informational message for users without the download capability.

= 2.6.0 =
* Feature: Enqueue JavaScript to auto-expand `<details>` elements when printing.

= 2.5.0 =
* Feature: Show plugin description inside `<details>`/`<summary>` when shortcode is rendered on a page or post.
* Feature: Added `is_single()`/`is_page()` context check to conditionally show the description block.

= 2.4.0 =
* Feature: Added print styles to hide the download button, header, and footer when printing.

= 2.3.0 =
* Feature: Added network-active status display for multisite installs.

= 2.2.0 =
* Refine: Removed `<details>`/`<summary>` wrapper from description in default (non-page) view.

= 2.1.2 =
* Fix: Use `current_time()` instead of `date()` for JSON filename timestamp to respect WordPress timezone settings (Fixes PHPCS error).

= 2.1.1 =
* Feature: Wrap plugin descriptions in `<details>`/`<summary>` tags for a cleaner default view.
* Fix: Add required `translators:` comments for internationalization functions with placeholders (Fixes Plugin Check error).
* Fix: Ensure `<details>` and `<summary>` tags are allowed in `wp_kses` for the dashboard widget.
* Refine: Improve multisite plugin detection slightly.
* Refine: Use case-insensitive sorting for plugin lists.

= 2.1.0 =
* Refactor: Introduce helper function `get_plugin_insight_data()` to centralize data retrieval.
* Security: Regenerate plugin data on download instead of passing via POST.
* Security: Add capability checks (`activate_plugins`) for viewing list and downloading JSON.
* Security: Remove `nopriv` action hook for downloads.
* Feature: Add more plugin details (version, description, author, URIs) to data structure.
* Feature: Handle network-activated plugins on multisite installs.
* Improvement: Use `wp_json_encode()` for standard JSON output.
* Improvement: Use output buffering and proper escaping (`esc_*`, `wp_kses_post`) throughout HTML generation.
* Improvement: Make widget title translatable.
* Fix: Address various Plugin Check escaping errors.

= 2.0.2 =
* Initial version shared for review (contained shortcode, dashboard widget, basic JSON download via POST).

= 1.0.0 =
* (Internal/Previous Version) Initial concept release.

== Upgrade Notice ==

= 2.1.2 =
This version fixes a date function usage to correctly respect your WordPress timezone settings for the exported JSON filename.

= 2.1.0 =
Major security and code quality improvements. Download now requires Administrator privileges and data is regenerated securely on request. Dashboard widget output is properly escaped.

== Credits ==

Made with ❤️ by Pedro Matias for WordPress developers and admins.