<?php
namespace modules_insight;
/**
 * Plugin Name: Modules Insight
 * Plugin URI: https://github.com/matias2018/Plugin-List-Display
 * Description: Displays a list of installed plugins (active and inactive) via shortcode [plugin_list] and a dashboard widget. Includes WordPress version and active theme info. Allows downloading the list as JSON or CSV.
 * Version: 3.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
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

/**
 * Registers (but does not enqueue) the plugin's front-end assets.
 * Enqueuing happens inside the shortcode so assets only load on pages that use it.
 *
 * @since 2.9.2
 */
function modules_insight_register_assets() {
    wp_register_style( 'modules-insight-style', plugins_url( 'css/modules-insight.css', __FILE__ ), array(), '3.0.0' );
    wp_register_script( 'modules-insight-script', plugins_url( 'js/modules-insight.js', __FILE__ ), array(), '3.0.0', true );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\modules_insight_register_assets' );

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
            wp_enqueue_style( 'modules-insight-style' );
            wp_enqueue_script( 'modules-insight-script' );
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

            <div class="mi-download-buttons hideOnPrint" style="display:flex; gap:.5em; margin-top:1em; flex-wrap:wrap;">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="download_plugin_list_json">
                    <?php wp_nonce_field( 'download_plugin_list', 'plugin_list_nonce' ); ?>
                    <input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Download List as JSON', 'modules-insight' ); ?>">
                </form>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="download_plugin_list_csv">
                    <?php wp_nonce_field( 'download_plugin_list_csv', 'plugin_list_csv_nonce' ); ?>
                    <input type="submit" class="button button-secondary" value="<?php esc_attr_e( 'Download List as CSV', 'modules-insight' ); ?>">
                </form>
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

    // 3. Regenerate the data (more secure than trusting POST data)
    $data = get_plugin_insight_data();

    // 4. Prepare for download
    // Use current_time() to respect WordPress timezone settings for the date part of the filename
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

    $data     = get_plugin_insight_data();
    $filename = 'modules-insight-plugin-list-' . current_time( 'Y-m-d' ) . '.csv';

    header( 'Content-Type: text/csv; charset=' . get_option( 'blog_charset' ) );
    header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );

    $output = fopen( 'php://output', 'w' );

    // Site environment block
    fputcsv( $output, array( 'Site Info' ) );
    fputcsv( $output, array( 'WordPress Version', $data['site_info']['wp_version'] ) );
    fputcsv( $output, array( 'Active Theme', $data['site_info']['active_theme']['name'], $data['site_info']['active_theme']['version'], $data['site_info']['active_theme']['author'], $data['site_info']['active_theme']['theme_uri'] ) );
    fputcsv( $output, array() ); // blank separator row

    fputcsv( $output, array( 'Status', 'Name', 'Version', 'Path', 'Author', 'Plugin URI', 'Author URI', 'Network Active' ) );

    foreach ( $data['active'] as $plugin ) {
        fputcsv( $output, array(
            'Active',
            $plugin['name'],
            $plugin['version'],
            $plugin['path'],
            $plugin['author'],
            $plugin['plugin_uri'],
            $plugin['author_uri'],
            $plugin['network'] ? 'Yes' : 'No',
        ) );
    }

    foreach ( $data['inactive'] as $plugin ) {
        fputcsv( $output, array(
            'Inactive',
            $plugin['name'],
            $plugin['version'],
            $plugin['path'],
            $plugin['author'],
            $plugin['plugin_uri'],
            $plugin['author_uri'],
            'No',
        ) );
    }

    fclose( $output );
    exit;
}
add_action( 'admin_post_download_plugin_list_csv', __NAMESPACE__ . '\download_plugin_list_csv' );


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

    wp_add_dashboard_widget(
        'modules_insight_plugin_list_widget',          
        __( 'Modules Insight - Plugin List', 'modules-insight' ),
        __NAMESPACE__ . '\plugin_list_dashboard_widget' // Display function
    );
}
add_action( 'wp_dashboard_setup', __NAMESPACE__ . '\add_plugin_list_dashboard_widget' );

?>