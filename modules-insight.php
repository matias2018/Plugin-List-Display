<?php
namespace modules_insight;
/**
 * Plugin Name: Modules Insight
 * Plugin URI: https://aura-plugins.com
 * Description: Audit installed plugins, assess PHP and WordPress upgrade risk via the WordPress.org API, export reports as JSON/CSV/Excel or straight to a Google Sheet, and ask AI about the doubtful ones. Scan-on-demand — nothing runs automatically.
 * Version: 4.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author: Pedro Matias
 * Author URI: https://pedromatias.dev
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       modules-insight
 * Domain Path:       /languages/
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
    die;
}

define( 'MODULES_INSIGHT_VERSION', '4.0.4' );

/**
 * Allowed target versions for the risk evaluator, shared by the shortcode,
 * the export handlers and the Google Sheet / AI endpoints.
 */
const MI_ALLOWED_TARGET_PHP = array( '8.0', '8.1', '8.2', '8.3', '8.4', '8.5' );
const MI_ALLOWED_TARGET_WP  = array( '6.7', '6.8', '7.0', '7.1' );
const MI_DEFAULT_TARGET_PHP  = '8.3';
const MI_DEFAULT_TARGET_WP   = '7.1';

require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/google-sheets.php';
require_once __DIR__ . '/includes/xlsx.php';
require_once __DIR__ . '/includes/ai.php';

/**
 * Reads a POSTed target version, falling back to the default when it is not one
 * of the allowed values. Caller must have already verified the request nonce.
 *
 * @param string $key     POST key to read.
 * @param array  $allowed Allowed values.
 * @param string $default Default when the value is missing or invalid.
 * @return string
 */
function mi_sanitize_target( string $key, array $allowed, string $default ): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its own nonce first.
    $value = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
    return in_array( $value, $allowed, true ) ? $value : $default;
}

/**
 * Registers (but does not enqueue) the plugin's front-end assets.
 * Enqueuing happens inside the shortcode so assets only load on pages that use it.
 *
 * @since 2.9.2
 */
function modules_insight_register_assets() {
    wp_register_style( 'modules-insight-style', plugins_url( 'css/modules-insight.css', __FILE__ ), array(), MODULES_INSIGHT_VERSION );
    wp_register_script( 'modules-insight-script', plugins_url( 'js/modules-insight.js', __FILE__ ), array(), MODULES_INSIGHT_VERSION, true );
}
add_action( 'init', __NAMESPACE__ . '\modules_insight_register_assets' );

/**
 * Enqueues the plugin assets and hands the script the data it needs.
 * Safe to call more than once per request.
 */
function mi_enqueue_assets() {
    wp_enqueue_style( 'modules-insight-style' );
    wp_enqueue_script( 'modules-insight-script' );
    wp_localize_script( 'modules-insight-script', 'modulesInsight', array(
        'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
        'nonce'       => wp_create_nonce( 'modules_insight_compat' ),
        'aiAvailable' => mi_ai_available(),
        'settingsUrl' => admin_url( 'options-general.php?page=modules-insight' ),
        'i18n'        => array(
            /* translators: 1: plugin name, 2: plugin version, 3: target PHP version, 4: target WordPress version */
            'aiDefaultQuestion' => __( 'How likely is %1$s %2$s to break when upgrading to PHP %3$s and WordPress %4$s? What should I check?', 'modules-insight' ),
        ),
    ) );
}

function modules_insight_admin_assets( string $hook ) {
    if ( 'index.php' !== $hook || ! current_user_can( 'activate_plugins' ) ) {
        return;
    }
    mi_enqueue_assets();
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\modules_insight_admin_assets' );

/**
 * Helper function to retrieve and structure plugin data.
 * Avoids code repetition.
 *
 * @since 2.1.0
 * @return array structured plugin data.
 */
function get_plugin_insight_data() {
    $cached = get_transient( 'modules_insight_data' );
    if ( false !== $cached ) {
        return $cached;
    }

    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins     = get_plugins();
    $active_plugins  = \get_option( 'active_plugins', array() );
    $network_plugins = array();
    if ( is_multisite() ) {
        $network_plugins = get_site_option( 'active_sitewide_plugins', array() );
        if ( ! empty( $network_plugins ) ) {
            $active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
        }
    }
    $active_plugins = array_unique( $active_plugins );
    $active_index   = array_flip( $active_plugins ); // O(1) lookups instead of O(n) in_array

    $active_list   = array();
    $inactive_list = array();

    foreach ( $all_plugins as $plugin_path => $plugin_data ) {
        $is_network_active = isset( $network_plugins[ $plugin_path ] );
        $is_active         = isset( $active_index[ $plugin_path ] ) || $is_network_active;

        $plugin_info = array(
            'name'        => $plugin_data['Name'],
            'version'     => $plugin_data['Version'],
            'path'        => $plugin_path,
            'description' => $plugin_data['Description'],
            'author'      => $plugin_data['Author'],
            'plugin_uri'  => $plugin_data['PluginURI'],
            'author_uri'  => $plugin_data['AuthorURI'],
            'network'     => $is_network_active,
        );

        if ( $is_active ) {
            $active_list[] = $plugin_info;
        } else {
            $inactive_list[] = $plugin_info;
        }
    }

    usort( $active_list,   function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
    usort( $inactive_list, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );

    $summary = array(
        'total_plugins'  => count( $all_plugins ),
        'total_active'   => count( $active_list ),
        'total_inactive' => count( $inactive_list ),
    );

    $theme = wp_get_theme();

    $site_info = array(
        'wp_version'   => get_bloginfo( 'version' ),
        'active_theme' => array(
            'name'      => $theme->get( 'Name' ),
            'version'   => $theme->get( 'Version' ),
            'author'    => $theme->get( 'Author' ),
            'theme_uri' => $theme->get( 'ThemeURI' ),
        ),
    );

    $result = array(
        'site_info' => $site_info,
        'active'    => $active_list,
        'inactive'  => $inactive_list,
        'summary'   => $summary,
    );

    set_transient( 'modules_insight_data', $result, 5 * MINUTE_IN_SECONDS );

    return $result;
}

function clear_plugin_insight_cache() {
    delete_transient( 'modules_insight_data' );
}
add_action( 'activated_plugin',          __NAMESPACE__ . '\clear_plugin_insight_cache' );
add_action( 'deactivated_plugin',        __NAMESPACE__ . '\clear_plugin_insight_cache' );
add_action( 'upgrader_process_complete', __NAMESPACE__ . '\clear_plugin_insight_cache' );
add_action( 'switch_theme',              __NAMESPACE__ . '\clear_plugin_insight_cache' );

/**
 * Maps a WordPress version string to the minimum PHP version it implies was tested.
 * Used as a third signal to soften age-driven High ratings.
 *
 * @param string $wp_ver WordPress version, e.g. "6.5" or "7.0".
 * @return float Implied minimum PHP version.
 */
function mi_wp_version_to_php( string $wp_ver ): float {
    $v = (float) $wp_ver;
    if ( $v >= 7.1 ) return 8.2;
    if ( $v >= 7.0 ) return 8.0;
    if ( $v >= 6.3 ) return 7.4;
    if ( $v >= 6.0 ) return 7.0;
    return 5.6;
}

/**
 * Maps a "major.minor" WordPress version to a monotonic release ordinal
 * ( major * 10 + minor ), so "releases behind" can be counted across the
 * 6.x → 7.0 boundary. Relies on the historical rule that the minor number
 * resets to 0 at a major bump (5.9 → 6.0).
 *
 * @param string $ver WordPress version, e.g. "6.8" or "7.1".
 * @return int Release ordinal, or 0 when unparseable.
 */
function mi_wp_release_ordinal( string $ver ): int {
    if ( ! preg_match( '/^(\d+)\.(\d+)/', trim( $ver ), $m ) ) {
        return 0;
    }
    return (int) $m[1] * 10 + (int) $m[2];
}

/**
 * Derives a WordPress upgrade risk level from cached WordPress.org compat data.
 * Mirrors calcWpRisk() in modules-insight.js. Uses the "Tested up to" value and
 * the last-updated age — no extra WordPress.org API fields are required.
 *
 * @param array  $compat    Transient data for a single plugin slug.
 * @param string $target_wp Target WordPress version, e.g. "7.1".
 * @return string 'low' | 'medium' | 'high' | 'not_on_wp_org' | 'unknown'
 */
function calculate_wp_compat_risk( array $compat, string $target_wp = MI_DEFAULT_TARGET_WP ): string {
    if ( ! empty( $compat['not_found'] ) ) {
        return 'not_on_wp_org';
    }

    $tested = (string) ( $compat['tested_up_to'] ?? '' );
    if ( '' === $tested ) {
        return 'unknown';
    }

    $behind = mi_wp_release_ordinal( $target_wp ) - mi_wp_release_ordinal( $tested );

    $last_updated = $compat['last_updated'] ?? '';
    $age_months   = $last_updated
        ? ( time() - (int) strtotime( $last_updated ) ) / ( 60 * 60 * 24 * 30.44 )
        : 999;

    if ( $behind <= 0 ) {
        return 'low';
    }
    if ( 1 === $behind && $age_months <= 18 ) {
        return 'low';
    }
    if ( $behind >= 5 || $age_months > 36 ) {
        return 'high';
    }
    if ( $behind <= 2 && $age_months <= 24 ) {
        return 'medium';
    }
    return 'medium';
}

/**
 * Derives a PHP upgrade risk level from cached WordPress.org compat data.
 * Mirrors the calcRisk() logic in modules-insight.js.
 *
 * @param array  $compat     Transient data for a single plugin slug.
 * @param string $target_php Target PHP version to evaluate against, e.g. '8.3'.
 * @return string 'low' | 'medium' | 'high' | 'not_on_wp_org' | 'unknown'
 */
function calculate_compat_risk( array $compat, string $target_php = '8.3' ): string {
    if ( ! empty( $compat['not_found'] ) ) {
        return 'not_on_wp_org';
    }

    $last_updated = $compat['last_updated'] ?? '';
    $requires_php = (float) ( $compat['requires_php'] ?? 0 );

    if ( empty( $last_updated ) ) {
        return 'unknown';
    }

    $age_months = ( time() - (int) strtotime( $last_updated ) ) / ( 60 * 60 * 24 * 30.44 );

    // Per-target Low thresholds: [ [age_limit_months, min_php], ... ] — first match wins.
    $low_paths = array(
        '8.0' => array( array( 18, 7.4 ), array( 12, 7.2 ) ),
        '8.1' => array( array( 18, 7.4 ), array( 12, 7.4 ) ),
        '8.2' => array( array( 18, 8.0 ), array( 12, 7.4 ) ),
        '8.3' => array( array( 18, 8.0 ), array( 12, 7.4 ) ),
        '8.4' => array( array( 18, 8.1 ), array( 12, 8.0 ) ),
        '8.5' => array( array( 18, 8.2 ), array( 12, 8.1 ) ),
    );
    $paths = $low_paths[ $target_php ] ?? $low_paths['8.3'];

    if ( $age_months > 36 || ( $requires_php > 0 && $requires_php < 7.0 ) ) {
        $risk = 'high';
    } else {
        $risk = 'medium';
        foreach ( $paths as $path ) {
            if ( $age_months <= $path[0] && $requires_php > 0 && $requires_php >= $path[1] ) {
                $risk = 'low';
                break;
            }
        }
    }

    // Soften age-driven High → Medium when "Tested up to" implies recent PHP testing.
    // Does not apply when High is caused by a declared PHP floor below 7.0.
    if ( 'high' === $risk && ( $requires_php >= 7.0 || ! $requires_php ) ) {
        $tested_up_to = $compat['tested_up_to'] ?? '';
        if ( $tested_up_to && mi_wp_version_to_php( $tested_up_to ) >= 7.4 ) {
            $risk = 'medium';
        }
    }

    return $risk;
}

/**
 * Returns cached WordPress.org compat data for a plugin slug, shaped for export.
 *
 * @param string $slug Plugin directory slug.
 * @return array
 */
function get_compat_export_data( string $slug, string $target_php = MI_DEFAULT_TARGET_PHP, string $target_wp = MI_DEFAULT_TARGET_WP ): array {
    $cached = get_transient( 'mi_compat_' . sanitize_key( $slug ) );
    if ( false === $cached ) {
        return array( 'status' => 'not_checked' );
    }
    if ( ! empty( $cached['not_found'] ) ) {
        return array(
            'status'  => 'not_on_wp_org',
            'risk'    => 'not_on_wp_org',
            'wp_risk' => 'not_on_wp_org',
        );
    }
    return array(
        'status'        => 'checked',
        'last_updated'  => $cached['last_updated']  ?? '',
        'tested_up_to'  => $cached['tested_up_to']  ?? '',
        'requires_php'  => $cached['requires_php']  ?? '',
        'risk'          => calculate_compat_risk( $cached, $target_php ),
        'wp_risk'       => calculate_wp_compat_risk( $cached, $target_wp ),
    );
}

/**
 * AJAX handler — fetches plugin info from WordPress.org for a single slug.
 * Results are cached per slug for 24 hours.
 */
function ajax_check_compat() {
    check_ajax_referer( 'modules_insight_compat', 'nonce' );

    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_send_json_error( 'Forbidden', 403 );
    }

    $slug = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
    if ( ! $slug ) {
        wp_send_json_error( 'Missing slug' );
    }

    $cache_key = 'mi_compat_' . $slug;
    $cached    = get_transient( $cache_key );
    // Only use cache if it was stored by 3.2.0+ (has tested_up_to) or is a not_found entry.
    if ( false !== $cached && ( ! empty( $cached['not_found'] ) || array_key_exists( 'tested_up_to', $cached ) ) ) {
        wp_send_json_success( $cached );
    }

    if ( ! function_exists( 'plugins_api' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    }

    $response = plugins_api( 'plugin_information', array(
        'slug'   => $slug,
        'fields' => array(
            'last_updated' => true,
            'tested'       => true,
            'requires_php' => true,
            'sections'     => false,
            'tags'         => false,
            'screenshots'  => false,
            'reviews'      => false,
            'versions'     => false,
        ),
    ) );

    if ( is_wp_error( $response ) ) {
        $data = array( 'not_found' => true );
        set_transient( $cache_key, $data, DAY_IN_SECONDS );
        wp_send_json_success( $data );
    }

    $data = array(
        'not_found'    => false,
        'last_updated' => $response->last_updated ?? '',
        'tested_up_to' => $response->tested        ?? '',
        'requires_php' => $response->requires_php  ?? '',
    );

    set_transient( $cache_key, $data, DAY_IN_SECONDS );
    wp_send_json_success( $data );
}
add_action( 'wp_ajax_modules_insight_check_compat', __NAMESPACE__ . '\ajax_check_compat' );


/**
 * Shortcode to display the list of active and inactive plugins.
 * Usage: [plugin_list]
 *
 * @since 2.0.0
 * @since 2.1.0 Refactored HTML generation, added escaping, uses helper function.
 * @since 2.1.1 Added details/summary for description, added translator comments.
 * @since 2.2.0 removed details/summary for description
 * @since 2.3.0 Added network active status
 * @since 2.4.0 Added styles to hide the download button when printing.
 * @since 2.4.0 Added styles to hide header and footer when printing.
 * @since 2.5.0 Added if statement to check if display is on a page/post
 * @since 2.5.0 If display is on a page/post then show the description in a details/summary tag
 * @since 2.6.0 Added JavaScript to open the details/summary tag on @media Print
 * @since 2.7.0 Added a message if the user cannot see the download button
 * @since 2.7.2 Added specific styles for Avada theme (custom footer)
 * @since 2.8.0 Fixed project GIT url to https://github.com/matias2018/Plugin-List-Display
 * @since 2.9.0 Changed the header of report to show the target and date
 * @since 2.9.0 Added the URL of the site to the report
 * @return string HTML output for the plugin list.
 */

function plugin_list_shortcode() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return sprintf( '<p>%s</p>', esc_html__( 'You do not have permission to view this information.', 'modules-insight' ) );
    }

    $scan_requested = isset( $_POST['modules_insight_scan_nonce'] )
        && wp_verify_nonce( sanitize_key( $_POST['modules_insight_scan_nonce'] ), 'modules_insight_scan' );

    $is_page_or_post = is_single() || is_page();

    ob_start();
    ?>
    <div class="modules-insight-plugin-list">

        <form method="post" class="hideOnPrint">
            <?php wp_nonce_field( 'modules_insight_scan', 'modules_insight_scan_nonce' ); ?>
            <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Scan Plugins', 'modules-insight' ); ?>">
        </form>

        <?php if ( $scan_requested ) :
            mi_enqueue_assets();
            $data          = get_plugin_insight_data();
            $active_list   = $data['active'];
            $inactive_list = $data['inactive'];
            $summary       = $data['summary'];
            $site_info     = $data['site_info'];
        ?>

            <?php if ( $is_page_or_post ) : ?>
                <div class="MI-report-header">
                    <p>
                        <strong>TARGET: </strong>
                        <span class="report-title">
                            <?php echo esc_html( get_bloginfo( 'name' ) ); ?>
                        </span>
                    </p>
                    <p>
                        <strong>DATE: </strong>
                        <span class="report-date">
                            <?php echo esc_html( date_i18n( 'Y-m-d H:i:s' ) ); ?>
                                || <strong>URL: </strong>
                        </span>
                        <span class="report-url">
                            <?php echo esc_html( get_bloginfo( 'url' ) ); ?>
                        </span>
                    </p>
                </div>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Site Environment', 'modules-insight' ); ?></h2>
            <ul>
                <li>
                    <strong><?php esc_html_e( 'WordPress Version:', 'modules-insight' ); ?></strong>
                    <?php echo esc_html( $site_info['wp_version'] ); ?>
                </li>
                <li>
                    <strong><?php esc_html_e( 'Active Theme:', 'modules-insight' ); ?></strong>
                    <?php echo esc_html( $site_info['active_theme']['name'] ); ?>
                    (v<?php echo esc_html( $site_info['active_theme']['version'] ); ?>)
                    <?php if ( ! empty( $site_info['active_theme']['author'] ) ) : ?>
                        <?php esc_html_e( 'by', 'modules-insight' ); ?>
                        <?php echo esc_html( $site_info['active_theme']['author'] ); ?>
                    <?php endif; ?>
                    <?php if ( ! empty( $site_info['active_theme']['theme_uri'] ) ) : ?>
                        &mdash; <a href="<?php echo esc_url( $site_info['active_theme']['theme_uri'] ); ?>" target="_blank" rel="noopener noreferrer">
                            <?php esc_html_e( 'Theme URI', 'modules-insight' ); ?>
                        </a>
                    <?php endif; ?>
                </li>
            </ul>

            <h2><?php esc_html_e( 'Active Plugins', 'modules-insight' ); ?> (<?php echo (int) $summary['total_active']; ?>)</h2>
            <?php if ( ! empty( $active_list ) ) : ?>
                <ol>
                    <?php foreach ( $active_list as $plugin ) : ?>
                        <li>
                            <?php echo esc_html( $plugin['name'] ); ?> (v<?php echo esc_html( $plugin['version'] ); ?>)

                            <?php if ( $is_page_or_post ) : ?>
                                <details>
                                    <summary><?php esc_html_e( 'Description', 'modules-insight' ); ?></summary>
                                    <p><?php echo wp_kses_post( $plugin['description'] ); ?></p>
                                </details>
                            <?php endif; ?>
                            <?php if ( ! empty( $plugin['plugin_uri'] ) ) : ?>
                                <a href="<?php echo esc_url( $plugin['plugin_uri'] ); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e( 'Plugin URI', 'modules-insight' ); ?>
                                </a>
                            <?php endif; ?>||
                            <?php if ( ! empty( $plugin['author_uri'] ) ) : ?>
                                <a href="<?php echo esc_url( $plugin['author_uri'] ); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php esc_html_e( 'Author URI', 'modules-insight' ); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ( $plugin['network'] ) : ?>
                                <strong>[<?php esc_html_e( 'Network Active', 'modules-insight' ); ?>]</strong>
                            <?php endif; ?>
                        </li>
                        <hr>
                    <?php endforeach; ?>
                </ol>
            <?php else : ?>
                <p><?php esc_html_e( 'No active plugins found.', 'modules-insight' ); ?></p>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Inactive Plugins', 'modules-insight' ); ?> (<?php echo (int) $summary['total_inactive']; ?>)</h2>
            <?php if ( ! empty( $inactive_list ) ) : ?>
                <ul>
                    <?php foreach ( $inactive_list as $plugin ) : ?>
                        <li>
                            <?php echo esc_html( $plugin['name'] ); ?> (v<?php echo esc_html( $plugin['version'] ); ?>)
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p><?php esc_html_e( 'No inactive plugins found.', 'modules-insight' ); ?></p>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Summary', 'modules-insight' ); ?></h2>
            <ul>
                <li>
                    <?php
                        /* translators: %d: Number of plugins. */
                        printf( esc_html__( 'Total Plugins: %d', 'modules-insight' ), (int) $summary['total_plugins'] );
                    ?>
                </li>
                <li>
                    <?php
                        /* translators: %d: Number of active plugins. */
                        printf( esc_html__( 'Total Active Plugins: %d', 'modules-insight' ), (int) $summary['total_active'] );
                    ?>
                </li>
                <li>
                    <?php
                        /* translators: %d: Number of inactive plugins. */
                        printf( esc_html__( 'Total Inactive Plugins: %d', 'modules-insight' ), (int) $summary['total_inactive'] );
                    ?>
                </li>
            </ul>

            <?php
            $ai_available = mi_ai_available();
            $gsheet_url   = mi_get_option( 'gsheet_webhook_url' );
            ?>
            <h2><?php esc_html_e( 'Upgrade Compatibility Check', 'modules-insight' ); ?></h2>
            <div class="mi-compat-controls hideOnPrint" style="display:flex; align-items:center; gap:.75em; flex-wrap:wrap; margin-bottom:.5em;">
                <label for="mi-target-php"><?php esc_html_e( 'Target PHP:', 'modules-insight' ); ?></label>
                <select id="mi-target-php">
                    <?php foreach ( MI_ALLOWED_TARGET_PHP as $v ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $v, MI_DEFAULT_TARGET_PHP ); ?>>PHP <?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="mi-target-wp"><?php esc_html_e( 'Target WP:', 'modules-insight' ); ?></label>
                <select id="mi-target-wp">
                    <?php foreach ( MI_ALLOWED_TARGET_WP as $v ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $v, MI_DEFAULT_TARGET_WP ); ?>>WP <?php echo esc_html( $v ); ?></option>
                    <?php endforeach; ?>
                </select>
                <button id="mi-check-compat" class="button button-secondary"
                        data-count="<?php echo (int) $summary['total_plugins']; ?>">
                    <?php
                    printf(
                        /* translators: %d: total number of plugins */
                        esc_html__( 'Check Upgrade Compatibility (%d plugins)', 'modules-insight' ),
                        (int) $summary['total_plugins']
                    );
                    ?>
                </button>
            </div>
            <p id="mi-compat-progress" style="display:none;"></p>
            <div class="mi-compat-table-wrapper">
            <table id="mi-compat-table" class="mi-compat-table" style="display:none;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Plugin', 'modules-insight' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'modules-insight' ); ?></th>
                        <th><?php esc_html_e( 'Last Updated', 'modules-insight' ); ?></th>
                        <th><?php esc_html_e( 'Tested up to (WP)', 'modules-insight' ); ?></th>
                        <th><?php esc_html_e( 'Min PHP', 'modules-insight' ); ?></th>
                        <th id="mi-risk-col-header"><?php
                            /* translators: %s: target PHP version */
                            printf( esc_html__( 'Risk for PHP %s', 'modules-insight' ), esc_html( MI_DEFAULT_TARGET_PHP ) );
                        ?></th>
                        <th id="mi-wp-risk-col-header"><?php
                            /* translators: %s: target WordPress version */
                            printf( esc_html__( 'Risk for WP %s', 'modules-insight' ), esc_html( MI_DEFAULT_TARGET_WP ) );
                        ?></th>
                        <?php if ( $ai_available ) : ?>
                            <th class="mi-ai-col"><?php esc_html_e( 'Ask AI', 'modules-insight' ); ?></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( array( 'Active' => $active_list, 'Inactive' => $inactive_list ) as $status_label => $list ) :
                        foreach ( $list as $plugin ) :
                            $row_slug = explode( '/', $plugin['path'] )[0];
                    ?>
                    <tr data-slug="<?php echo esc_attr( $row_slug ); ?>" data-name="<?php echo esc_attr( $plugin['name'] ); ?>" data-version="<?php echo esc_attr( $plugin['version'] ); ?>">
                        <td><?php echo esc_html( $plugin['name'] ); ?></td>
                        <td>
                            <?php
                            echo 'Active' === $status_label
                                ? esc_html__( 'Active', 'modules-insight' )
                                : esc_html__( 'Inactive', 'modules-insight' );
                            ?>
                        </td>
                        <td class="mi-last-updated">—</td>
                        <td class="mi-tested-up-to">—</td>
                        <td class="mi-requires-php">—</td>
                        <td class="mi-risk">—</td>
                        <td class="mi-wp-risk">—</td>
                        <?php if ( $ai_available ) : ?>
                            <td class="mi-ai-cell">—</td>
                        <?php endif; ?>
                    </tr>
                    <?php
                        endforeach;
                    endforeach;
                    ?>
                </tbody>
            </table>
            </div><!-- .mi-compat-table-wrapper -->

            <?php if ( $ai_available ) : ?>
                <div id="mi-ai-panel" class="hideOnPrint" hidden>
                    <p class="mi-ai-panel-title"></p>
                    <textarea id="mi-ai-question" rows="3"></textarea>
                    <div>
                        <button id="mi-ai-ask" class="button button-secondary"><?php esc_html_e( 'Ask', 'modules-insight' ); ?></button>
                        <button id="mi-ai-close" class="button-link"><?php esc_html_e( 'Close', 'modules-insight' ); ?></button>
                    </div>
                    <div id="mi-ai-answer" aria-live="polite"></div>
                </div>
            <?php endif; ?>

            <div class="mi-download-buttons hideOnPrint" style="display:flex; gap:.5em; margin-top:1em; flex-wrap:wrap; align-items:center;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="download_plugin_list_json">
                    <input type="hidden" name="mi_target_php" class="mi-target-php-input" value="<?php echo esc_attr( MI_DEFAULT_TARGET_PHP ); ?>">
                    <input type="hidden" name="mi_target_wp" class="mi-target-wp-input" value="<?php echo esc_attr( MI_DEFAULT_TARGET_WP ); ?>">
                    <?php wp_nonce_field( 'download_plugin_list', 'plugin_list_nonce' ); ?>
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Download List as JSON', 'modules-insight' ); ?>">
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="download_plugin_list_csv">
                    <input type="hidden" name="mi_target_php" class="mi-target-php-input" value="<?php echo esc_attr( MI_DEFAULT_TARGET_PHP ); ?>">
                    <input type="hidden" name="mi_target_wp" class="mi-target-wp-input" value="<?php echo esc_attr( MI_DEFAULT_TARGET_WP ); ?>">
                    <?php wp_nonce_field( 'download_plugin_list_csv', 'plugin_list_csv_nonce' ); ?>
                    <input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Download List as CSV', 'modules-insight' ); ?>">
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="download_plugin_list_xlsx">
                    <input type="hidden" name="mi_target_php" class="mi-target-php-input" value="<?php echo esc_attr( MI_DEFAULT_TARGET_PHP ); ?>">
                    <input type="hidden" name="mi_target_wp" class="mi-target-wp-input" value="<?php echo esc_attr( MI_DEFAULT_TARGET_WP ); ?>">
                    <?php wp_nonce_field( 'download_plugin_list_xlsx', 'plugin_list_xlsx_nonce' ); ?>
                    <input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Download List as Excel (for SharePoint)', 'modules-insight' ); ?>">
                </form>
                <?php if ( $gsheet_url ) : ?>
                    <button id="mi-push-gsheet" class="button button-secondary"
                            data-php="<?php echo esc_attr( MI_DEFAULT_TARGET_PHP ); ?>"
                            data-wp="<?php echo esc_attr( MI_DEFAULT_TARGET_WP ); ?>">
                        <?php esc_html_e( 'Send report to Google Sheet', 'modules-insight' ); ?>
                    </button>
                    <span id="mi-gsheet-status" aria-live="polite"></span>
                <?php else : ?>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=modules-insight' ) ); ?>">
                        <?php esc_html_e( 'Configure Google Sheets export →', 'modules-insight' ); ?>
                    </a>
                <?php endif; ?>
            </div>

        <?php endif; // $scan_requested ?>

    </div>
    <?php
    return ob_get_clean();
}
add_shortcode( 'plugin_list', __NAMESPACE__ . '\plugin_list_shortcode' );


/**
 * Handles the download request for the plugin list JSON file.
 * Hooked to admin_post action.
 *
 * @since 2.0.0
 * @since 2.1.0 Refactored to regenerate data, use capability checks, and wp_json_encode.
 */
function download_plugin_list_json() {
    // 1. Verify nonce
    if ( ! isset( $_POST['plugin_list_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['plugin_list_nonce'] ), 'download_plugin_list' ) ) {
        wp_die( esc_html__( 'Invalid security token.', 'modules-insight' ), esc_html__( 'Nonce Error', 'modules-insight' ), array( 'response' => 403 ) );
    }

    // 2. Check user capability (important!) - Must match the check in the shortcode
    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to download this file.', 'modules-insight' ), esc_html__( 'Permission Denied', 'modules-insight' ), array( 'response' => 403 ) );
    }

    $target_php = mi_sanitize_target( 'mi_target_php', MI_ALLOWED_TARGET_PHP, MI_DEFAULT_TARGET_PHP );
    $target_wp  = mi_sanitize_target( 'mi_target_wp', MI_ALLOWED_TARGET_WP, MI_DEFAULT_TARGET_WP );

    $data = get_plugin_insight_data();
    $data['targets'] = array( 'php' => $target_php, 'wp' => $target_wp );

    foreach ( array( 'active', 'inactive' ) as $bucket ) {
        foreach ( $data[ $bucket ] as &$plugin ) {
            $plugin['compat'] = get_compat_export_data( explode( '/', $plugin['path'] )[0], $target_php, $target_wp );
        }
        unset( $plugin );
    }

    $filename = 'modules-insight-plugin-list-' . current_time( 'Y-m-d' ) . '.json';

    // 5. Set headers and output JSON
    header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' ); // Sanitize filename!

    echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    exit; // Terminate script execution
}
// Hook only for logged-in users via admin-post
add_action( 'admin_post_download_plugin_list_json', __NAMESPACE__ . '\download_plugin_list_json' );


/**
 * Handles the download request for the plugin list CSV file.
 */
function download_plugin_list_csv() {
    if ( ! isset( $_POST['plugin_list_csv_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['plugin_list_csv_nonce'] ), 'download_plugin_list_csv' ) ) {
        wp_die( esc_html__( 'Invalid security token.', 'modules-insight' ), esc_html__( 'Nonce Error', 'modules-insight' ), array( 'response' => 403 ) );
    }

    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to download this file.', 'modules-insight' ), esc_html__( 'Permission Denied', 'modules-insight' ), array( 'response' => 403 ) );
    }

    $target_php = mi_sanitize_target( 'mi_target_php', MI_ALLOWED_TARGET_PHP, MI_DEFAULT_TARGET_PHP );
    $target_wp  = mi_sanitize_target( 'mi_target_wp', MI_ALLOWED_TARGET_WP, MI_DEFAULT_TARGET_WP );

    $filename = 'modules-insight-plugin-list-' . current_time( 'Y-m-d' ) . '.csv';

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

    $output = fopen( 'php://output', 'w' );
    // UTF-8 BOM: without it Excel reads the file in the machine's legacy code
    // page and mangles accented names once the download loses its charset header.
    fwrite( $output, "\xEF\xBB\xBF" );
    foreach ( mi_build_report_rows( $target_php, $target_wp ) as $row ) {
        fputcsv( $output, $row, ',', '"', '' );
    }
    // No fclose(): php://output is a write-through wrapper to the SAPI and is
    // released on exit; closing it explicitly trips Plugin Check's filesystem sniff.
    exit;
}
add_action( 'admin_post_download_plugin_list_csv', __NAMESPACE__ . '\download_plugin_list_csv' );


/**
 * Handles the download request for the plugin list as a native Excel .xlsx file.
 * Preferred for Excel Online / SharePoint, where CSV delimiter and encoding
 * detection is unreliable.
 */
function download_plugin_list_xlsx() {
    if ( ! isset( $_POST['plugin_list_xlsx_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['plugin_list_xlsx_nonce'] ), 'download_plugin_list_xlsx' ) ) {
        wp_die( esc_html__( 'Invalid security token.', 'modules-insight' ), esc_html__( 'Nonce Error', 'modules-insight' ), array( 'response' => 403 ) );
    }

    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_die( esc_html__( 'You do not have sufficient permissions to download this file.', 'modules-insight' ), esc_html__( 'Permission Denied', 'modules-insight' ), array( 'response' => 403 ) );
    }

    $target_php = mi_sanitize_target( 'mi_target_php', MI_ALLOWED_TARGET_PHP, MI_DEFAULT_TARGET_PHP );
    $target_wp  = mi_sanitize_target( 'mi_target_wp', MI_ALLOWED_TARGET_WP, MI_DEFAULT_TARGET_WP );

    $xlsx     = mi_build_xlsx_document( mi_build_report_rows( $target_php, $target_wp, false ) );
    $filename = 'modules-insight-plugin-list-' . current_time( 'Y-m-d' ) . '.xlsx';

    header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
    header( 'Content-Length: ' . strlen( $xlsx ) );

    echo $xlsx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary XLSX payload, not HTML.
    exit;
}
add_action( 'admin_post_download_plugin_list_xlsx', __NAMESPACE__ . '\download_plugin_list_xlsx' );


/**
 * Callback function to display the plugin list in the dashboard widget.
 *
 * @since 2.0.0
 * @since 2.1.0 Added wp_kses_post for escaping.
 */
function plugin_list_dashboard_widget() {
    // Output is fully escaped at source inside plugin_list_shortcode().
    echo plugin_list_shortcode(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Adds the plugin list dashboard widget.
 *
 * @since 2.0.0
 * @since 2.1.0 Made title translatable.
 */
function add_plugin_list_dashboard_widget() {
    if ( ! current_user_can( 'activate_plugins' ) ) {
        return;
    }

    $icon = '<img src="' . esc_url( plugins_url( 'assets/icon-128x128.png', __FILE__ ) ) . '" '
          . 'style="height:1em;width:1em;vertical-align:middle;margin-right:.4em;border-radius:3px;object-fit:cover;" alt="">';
    wp_add_dashboard_widget(
        'modules_insight_plugin_list_widget',
        $icon . __( 'Modules Insight', 'modules-insight' ),
        __NAMESPACE__ . '\plugin_list_dashboard_widget'
    );
}
add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\add_plugin_list_dashboard_widget' );

?>