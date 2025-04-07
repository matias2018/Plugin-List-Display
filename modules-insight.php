<?php
namespace modules_insight;
/**
 * Plugin Name: Modules Insight
 * Plugin URI: https://github.com/matias2018/Plugin-List-Display
 * Description: Displays a list of installed plugins (active and inactive) via shortcode [plugin_list] and a dashboard widget. Allows downloading the list as JSON.
 * Version: 2.8.0
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
 * @since 2.4.0
 * Added styles to hide the download button when printing.
 * Also hide header and footer when printing.
 * This is a temporary solution until we can implement a more robust method.
*/
// Enqueue css file
function modules_insight_enqueue_styles() {
    // Enqueue the CSS file for the plugin
    wp_enqueue_style( 'modules-insight-style', plugins_url( 'css/modules-insight.css', __FILE__ ), array(), '2.3.0' );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\modules_insight_enqueue_styles' );

/**
 * Enqueue the JavaScript file for the plugin. Used for opening the details/summary tag on @media Print. 
 *
 * @since 2.6.0
 */
function modules_insight_enqueue_scripts() {
    // Enqueue the JS file for the plugin
    wp_enqueue_script( 'modules-insight-script', plugins_url( 'js/modules-insight.js', __FILE__ ), array(), '2.3.0', true );
}
add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\modules_insight_enqueue_scripts' );

/**
 * Helper function to retrieve and structure plugin data.
 * Avoids code repetition.
 *
 * @since 2.1.0
 * @return array structured plugin data.
 */
function get_plugin_insight_data() {
    if ( ! function_exists( 'get_plugins' ) ) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins    = get_plugins();
    // Get network-activated plugins if on multisite admin. Check site option as fallback.
    $active_plugins = get_option( 'active_plugins', array() );
    if ( is_multisite() ) {
        $network_plugins = get_site_option( 'active_sitewide_plugins', array() );
        if ( ! empty( $network_plugins ) ) {
            $active_plugins = array_merge( $active_plugins, array_keys( $network_plugins ) );
        }
    }
    // Ensure uniqueness if merged
    $active_plugins = array_unique( $active_plugins );

    $active_list   = array();
    $inactive_list = array();

    foreach ( $all_plugins as $plugin_path => $plugin_data ) {
        $is_active = in_array( $plugin_path, $active_plugins, true );
        $is_network_active = false;

        // Check network activation status if multisite
        if ( is_multisite() ) {
            $network_plugins = get_site_option( 'active_sitewide_plugins', array() );
            if ( isset( $network_plugins[ $plugin_path ] ) ) {
                $is_network_active = true;
                 $is_active = true; // Ensure it's listed as active
            }
        }

        // Basic plugin info - keep raw for JSON, escape during HTML output.
        $plugin_info = array(
            'name'        => $plugin_data['Name'],
            'version'     => $plugin_data['Version'],
            'path'        => $plugin_path,
            'description' => $plugin_data['Description'],
            'author'      => $plugin_data['Author'],
            'plugin_uri'  => $plugin_data['PluginURI'],
            'author_uri'  => $plugin_data['AuthorURI'],
            'network'     => $is_network_active, // Network status
        );

        if ( $is_active ) {
            $active_list[] = $plugin_info;
        } else {
            $inactive_list[] = $plugin_info;
        }
    }

    // Sort alphabetically by name
    usort($active_list, function($a, $b) { return strcasecmp($a['name'], $b['name']); }); // Case-insensitive sort
    usort($inactive_list, function($a, $b) { return strcasecmp($a['name'], $b['name']); }); // Case-insensitive sort


    $summary = array(
        'total_plugins'  => count( $all_plugins ),
        'total_active'   => count( $active_list ),
        'total_inactive' => count( $inactive_list ),
    );

    return array(
        'active'   => $active_list,
        'inactive' => $inactive_list,
        'summary'  => $summary,
    );
}


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
 * @return string HTML output for the plugin list.
 */

function plugin_list_shortcode() {
    // Check if user has capability to view plugins - adjust if needed for frontend use
    if ( ! current_user_can( 'activate_plugins' ) && ! is_admin() ) {
        return sprintf( '<p>%s</p>', esc_html__( 'You do not have permission to view this information.', 'modules-insight' ) );
    }

    $data          = get_plugin_insight_data();
    $active_list   = $data['active'];
    $inactive_list = $data['inactive'];
    $summary       = $data['summary'];

    // Use output buffering for cleaner HTML construction
    ob_start();
    ?>
    <div class="modules-insight-plugin-list">

        <h2><?php esc_html_e( 'Active Plugins', 'modules-insight' ); ?> (<?php echo (int) $summary['total_active']; ?>)</h2>
        <?php if ( ! empty( $active_list ) ) : ?>
            <ol>
                <?php foreach ( $active_list as $plugin ) : ?>
                    <li>
                        <?php echo esc_html( $plugin['name'] ); ?> (v<?php echo esc_html( $plugin['version'] ); ?>)

                        <!-- If page/post display description -->
                        <?php if ( is_single() || is_page() ) : ?>
                                <details>
                                    <summary><?php esc_html_e( 'Description', 'modules-insight' ); ?></summary>
                                    <p><?php echo wp_kses_post( $plugin['description'] ); ?></p>
                                </details>
                            <?php endif; ?>
                            <!-- Optional: Add links to plugin URI and author URI -->
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
                            <!-- Optional: Add network active status -->
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
        // --- Download Button Section ---
        // IMPORTANT: Only show download button if user has 'activate_plugins' capability (usually Administrators).
        ?>
        <?php if ( current_user_can( 'activate_plugins' ) ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 1em;">
                <input type="hidden" name="action" value="download_plugin_list_json">
                <?php wp_nonce_field( 'download_plugin_list', 'plugin_list_nonce' ); ?>
                <input type="hidden" name="plugin_list_nonce" value="<?php echo esc_attr( wp_create_nonce( 'download_plugin_list' ) ); ?>">
                <input type="hidden" name="plugin_list" value="<?php echo esc_attr( wp_json_encode( $data ) ); ?>">
                <!-- Hide if list is being displayed on a page instead of admin dashboard -->
                <input type="submit" class="button button-primary hideOnPrint" value="<?php esc_attr_e( 'Download List as JSON', 'modules-insight' ); ?>">
            </form>
        <?php else : ?>
            <?php // Optional: Add a message if the user *cannot* see the button ?>
            <p><small><?php esc_html_e( 'Download option available for administrators.', 'modules-insight' ); ?></small></p>
        <?php endif; ?>

    </div>
    <?php
    // Return the buffered content
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
 * Callback function to display the plugin list in the dashboard widget.
 *
 * @since 2.0.0
 * @since 2.1.0 Added wp_kses_post for escaping.
 */
function plugin_list_dashboard_widget() {
    // Echo the shortcode output. Wrap in wp_kses_post to allow the specific HTML
    // tags generated by the shortcode (headings, lists, paragraphs, form elements, details, summary).
    // We need to add details and summary to the allowed tags for wp_kses_post.
    $allowed_html = array_merge(
        wp_kses_allowed_html( 'post' ), // Get standard post tags
        array( // Add our specific tags and attributes
            'details' => array(
                'style' => true,
                'open' => true, // Allow the 'open' attribute if needed
            ),
            'summary' => array(
                'style' => true,
            ),
            'form' => array(
                'method' => true,
                'action' => true,
                'style' => true,
            ),
            'input' => array(
                'type' => true,
                'name' => true,
                'value' => true,
                'class' => true,
            )
        )
    );
    echo wp_kses( plugin_list_shortcode(), $allowed_html );
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