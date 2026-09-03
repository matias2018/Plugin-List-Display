# Changelog

All notable changes to Modules Insight are documented in this file.

## [4.0.0] - 2026-09-03

### Added
- **WordPress upgrade risk** — a second risk column alongside PHP, rated against a selectable target WordPress version (6.7–7.1). Derived from each plugin's "Tested up to" value on WordPress.org and its last-updated age via a new `calculate_wp_compat_risk()` (mirrored by `calcWpRisk()` in JS). No additional WordPress.org API fields are fetched.
- **PHP 8.5** as a target-PHP option, with its own Low-risk thresholds.
- **Google Sheets export** — `Send report to Google Sheet` POSTs the report to a Google Apps Script Web App the site owner deploys once (URL + shared secret token, stored under Settings → Modules Insight or a `wp-config.php` constant). No Google Cloud project, no OAuth, no stored API keys. New `includes/google-sheets.php`.
- **Ask AI** — for plugins rated Medium / High / Not on WP.org, a free-text question answered through the WordPress 7.0 core AI Client (`wp_ai_client_prompt()`). Self-hides when the core API or a provider is unavailable. Answers cached 12 hours per question. New `includes/ai.php`.
- Dedicated **Settings → Modules Insight** screen (`includes/settings.php`) built with the Settings API.

### Changed
- The compatibility check now evaluates PHP and WordPress in a single pass; the button is relabelled "Check Upgrade Compatibility" and the section heading to "Upgrade Compatibility Check".
- JSON export records the selected `targets` and each plugin's `compat.wp_risk`; CSV gains a `WP <target> Risk` column. CSV/Sheets cell values beginning with `= + - @` are prefixed with `'` to prevent spreadsheet formula injection. CSV site-info block reformatted into the shared row builder.
- Asset `ver` strings are tied to `MODULES_INSIGHT_VERSION` again (were pinned at `3.2.0`).
- `modules-insight.php` reduced to a bootstrap that defines shared target constants and loads `includes/`.

### Fixed
- Translation files renamed `modules_insight-*` → `modules-insight-*` so WordPress loads them for the `modules-insight` text domain (they never loaded before). POT regenerated.
- `README.md` requirements corrected from "WordPress 5.2 / PHP 7.2" to "WordPress 6.0 / PHP 8.0" to match the plugin header.
- `Tested up to` raised to WordPress 7.1.

---

## [2.9.2] - 2026-05-15

### Fixed
- Removed duplicate nonce hidden field in the download form — `wp_nonce_field()` already outputs it; the manual `<input>` was redundant and fragile
- JS print listener now only closes `<details>` elements that were auto-opened for print, preserving any the user had manually opened beforehand
- Declared `elms` and `e` with `const` inside the print media listener — they were implicit globals previously

### Security
- Simplified shortcode capability check: removed the `is_admin()` context condition and rely solely on `current_user_can('activate_plugins')` — `is_admin()` checks the page context, not the user's role, which could allow unintended access

### Performance
- Moved `get_site_option('active_sitewide_plugins')` call outside the plugin `foreach` loop on multisite installs — it was executing one extra DB call per installed plugin
- Assets are now registered globally but only enqueued on pages where the `[plugin_list]` shortcode is present; previously loaded on every front-end page
- Updated asset version strings from `2.3.0` to `2.9.2` so browsers do not serve stale cached files

### Accessibility
- Replaced `outline: none` on the download button focus state with a visible `2px solid` outline — removing the outline broke keyboard navigation

### Maintenance
- Synced version numbers: `readme.txt` stable tag, CSS version comment, and JS version comment now all match the plugin header (`2.9.2`)
- Fixed screenshot order in `readme.txt` (was listed as 1, 3, 2, 4 — corrected to 1, 2, 3, 4); fixed typo in description
- Added missing changelog entries for versions 2.2.0 through 2.9.1 in `readme.txt`

### Developer
- Added `.vscode/settings.json` enabling Intelephense's built-in WordPress stubs, resolving P1010 false positives for WordPress functions across the file

---

## [2.9.1] - 2025

### Fixed
- Added `fusion-tb-footer` and `fusion-footer` CSS classes to print-hide rules for Avada theme compatibility

---

## [2.9.0] - 2025

### Added
- Report header now shows site name (TARGET), date, and URL when shortcode is rendered on a page or post

---

## [2.8.0] - 2025

### Fixed
- Corrected plugin GitHub URI to `https://github.com/matias2018/Plugin-List-Display`

---

## [2.7.2] - 2025

### Fixed
- Added print-hide CSS rules for Avada theme custom footer elements (`fusion-tb-footer`, `fusion-footer`)

---

## [2.7.0] - 2025

### Added
- Informational message shown to users who do not have the download capability

---

## [2.6.0] - 2025

### Added
- JavaScript to auto-expand `<details>` elements when the page is printed

---

## [2.5.0] - 2025

### Added
- Plugin description shown inside `<details>`/`<summary>` when shortcode is rendered on a page or post
- Context check (`is_single()` / `is_page()`) to conditionally display the description block

---

## [2.4.0] - 2025

### Added
- Print styles to hide the download button, page header, and footer when printing

---

## [2.3.0] - 2025

### Added
- Network-active status indicator for multisite installs

---

## [2.2.0] - 2025

### Changed
- Removed `<details>`/`<summary>` wrapper from the description in the default (non-page/post) view

---

## [2.1.2] - 2025

### Fixed
- Use `current_time()` instead of `date()` for the exported JSON filename to respect WordPress timezone settings

---

## [2.1.1] - 2025

### Added
- Plugin descriptions wrapped in `<details>`/`<summary>` tags for a cleaner default view

### Fixed
- Added required `translators:` comments for i18n functions with placeholders
- Ensured `<details>` and `<summary>` are allowed in `wp_kses` for the dashboard widget
- Improved multisite plugin detection
- Case-insensitive sorting for plugin lists

---

## [2.1.0] - 2025

### Added
- Helper function `get_plugin_insight_data()` centralises data retrieval
- More plugin details in the data structure: version, description, author, URIs
- Multisite support for network-activated plugins

### Security
- Plugin data is regenerated on download instead of passed via POST
- Capability checks (`activate_plugins`) required for viewing list and downloading JSON
- Removed `nopriv` action hook for the download endpoint

### Changed
- Use `wp_json_encode()` for standard JSON output
- Output buffering and proper escaping (`esc_*`, `wp_kses_post`) throughout HTML generation
- Dashboard widget title made translatable

---

## [2.0.2] - 2024

- Initial public release: shortcode, dashboard widget, basic JSON download via POST
