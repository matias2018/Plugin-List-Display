<?php
namespace PluginListDisplay;
/**
 * Plugin Name: Plugin List Display
 * Plugin URI: https://github.com/matias2018/Plugin-List-Display
 * Description: This is a plugin to display a list of active and inactive plugins.
 * Version: 2.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author: Pedro Matias
 * Author URI: https://pedromatias.dev
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       plugin-list-display
 * Domain Path:       /languages/
 */

/* !!! comment in production !!! */
// error_reporting(E_ALL);
// ini_set('display_errors', 1);

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

/**
 * Shortcode to display the list of active and inactive plugins.
 */
function plugin_list_shortcode() {
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $all_plugins = get_plugins();
    $active_plugins = get_option('active_plugins', array());

    $active_list = array();
    $inactive_list = array();

    foreach ($all_plugins as $plugin_path => $plugin_data) {
        $plugin_info = array(
            'name' => esc_html($plugin_data['Name']),
            'version' => esc_html($plugin_data['Version']),
            'path' => $plugin_path,
            'description' => esc_html($plugin_data['Description']),
        );
        if (in_array($plugin_path, $active_plugins)) {
            $active_list[] = $plugin_info;
        } else {
            $inactive_list[] = $plugin_info;
        }
    }

    $output = '<h2>Active Plugins:</h2>';
    $output .= '<ul>';
    foreach ($active_list as $plugin) {
        $output .= '<li>' . sanitize_text_field($plugin['name']) . '</li>';
    }
    $output .= '</ul>';

    $output .= '<h2>Inactive Plugins:</h2>';
    $output .= '<ul>';
    foreach ($inactive_list as $plugin) {
        $output .= '<li>' . sanitize_text_field($plugin['name']) . '</li>';
    }
    $output .= '</ul>';

    $summary = array(
        'total_plugins' => count($all_plugins),
        'total_active' => count($active_list),
        'total_inactive' => count($inactive_list),
    );

    $output .= '<h2>Summary:</h2>';
    $output .= '<ul>';
    $output .= '<li>Total Plugins: ' . $summary['total_plugins'] . '</li>';
    $output .= '<li>Total Plugins: ' . $summary['total_plugins'] . '</li>';
    $output .= '<li>Total Active Plugins: ' . $summary['total_active'] . '</li>';
    $output .= '<li>Total Inactive Plugins: ' . $summary['total_inactive'] . '</li>';
    $output .= '</ul>';

    $json_data = json_encode(array(
        'active' => $active_list,
        'inactive' => $inactive_list,
        'summary' => $summary,
    ), JSON_PRETTY_PRINT);

    $download_link = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    $download_link .= '<input type="hidden" name="action" value="download_plugin_list_json">';
    $download_link .= '<input type="hidden" name="plugin_list_data" value="' . esc_attr(base64_encode($json_data)) . '">';
    $download_link .= wp_nonce_field('download_plugin_list', 'plugin_list_nonce', true, false);
    $download_link .= '<input type="submit" value="Download JSON">';
    $download_link .= '</form>';

    $output .= $download_link;

    return $output;
}

/**
 * Handles the download of the plugin list JSON file.
 */
function download_plugin_list_json() {
    if (!isset($_POST['plugin_list_nonce']) || !wp_verify_nonce($_POST['plugin_list_nonce'], 'download_plugin_list')) {
        wp_die(__('Invalid request.', 'plugin-list-display'));
    }

    if (isset($_POST['plugin_list_data'])) {
        $json_data = base64_decode(sanitize_text_field($_POST['plugin_list_data']));
        $filename = 'plugin_list.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
        echo $json_data;
        exit;
    }
}

/**
 * Displays the plugin list in the dashboard widget.
 */
function plugin_list_dashboard_widget() {
    echo plugin_list_shortcode();
}

/**
 * Adds the plugin list dashboard widget.
 */
function add_plugin_list_dashboard_widget() {
    wp_add_dashboard_widget(
        'plugin_list_dashboard_widget',
        'Plugin List - The best plugin list ever!',
        __NAMESPACE__ . '\plugin_list_dashboard_widget'
    );
}

// Register the shortcode and actions.
add_shortcode('plugin_list', __NAMESPACE__ . '\plugin_list_shortcode');
add_action('admin_post_download_plugin_list_json', __NAMESPACE__ . '\download_plugin_list_json');
add_action('admin_post_nopriv_download_plugin_list_json', __NAMESPACE__ . '\download_plugin_list_json');
add_action('wp_dashboard_setup', __NAMESPACE__ . '\add_plugin_list_dashboard_widget');

?>