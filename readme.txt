=== Modules Insight ===
Contributors: Pedro Matias
Tags: plugin management, plugin report, admin tools, plugin status, developer tools
Requires at least: 5.2
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 2.5.0
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Provides a quick overview of installed WordPress plugins with their status, exportable as JSON.

== Description ==

**Modules Insight** is a simple WordPress plugin that lists all installed plugins, showing which are **active** and which are **inactive**. Ideal for developers and site managers needing a quick status overview.

MI adds a widget to your **Dashboard** and provides a shortcode `[plugin_list]` for displaying the plugin status list. It also allows **Administrators** to download the list as a `.json` report directly from the widget or shortcode output.

MI is completely read-only and does **not** make any changes to your site's plugin activation status.

=== ✨ Key Features ===

- 📋 Lists all installed plugins (active, inactive, network active)
- ✅ Includes plugin name and version. 
- 📊 Displays a summary count of plugins
- 📁 Allows **Administrators** to export plugin data as a `.json` report
- 🖥 Adds a convenient Dashboard Widget
- `[plugin_list]` Shortcode support for display anywhere
-   - Upcoming: Plugin description on generated page
- 🛡 100% read-only — safe for production use

=== 💡 Use Cases ===

- 🧾 Client reports on installed plugins
- 🚧 Pre-deployment or pre-update plugin checks
- 🔒 Identifying potentially unused plugins for cleanup
- 👥 Sharing plugin status easily with your team or support

== Installation ==

1. Upload the `modules-insight` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress plugin screen directly (Plugins > Add New).
2. Activate the plugin through the ‘Plugins’ menu in WordPress.
3. Check your **Dashboard** for the "Modules Insight - Plugin List" widget.
4. Alternatively, use the shortcode `[plugin_list]` on any page or post to display the list.
5. Administrators will see a "Download List as JSON" button within the widget/shortcode output.

== Frequently Asked Questions ==

= Does this plugin make any changes to my site? =
No. MI is read-only. It does not activate, deactivate, install, or delete any plugins.

= Who can see the plugin list and download the JSON file? =
By default, the list and download button are visible only to users with the `activate_plugins` capability (typically Administrators). You can adjust capability checks in the code if needed for other roles, but be mindful of security implications.

= What format is the export file? =
The plugin exports data as a `.json` file, timestamped with the date of export (according to your site's timezone).

= Can I use this on a live/production site? =
Yes! MI is completely safe to use on live sites as it performs no write operations.

== Screenshots ==

1. The Modules Insight dashboard widget showing active/inactive plugins.
2. Example of the collapsed description view using `<details>`.
3. The "Download List as JSON" button available to administrators.
4. Example structure of the exported JSON file.

== Changelog ==

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