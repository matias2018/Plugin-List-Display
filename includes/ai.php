<?php
namespace modules_insight;

/**
 * "Ask AI" — free-text questions about a single installed plugin, for the cases
 * where the metadata-based risk rating leaves genuine doubt.
 *
 * Uses the WordPress core AI Client (WordPress 7.0+, provider configured by the
 * site admin under Settings → AI). The feature self-hides everywhere when the
 * core API is missing or no provider is set up.
 *
 * @package modules_insight
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

/**
 * Whether the WordPress AI Client is present and able to generate text right now.
 * Memoised — the underlying check is documented as cheap but is still a method call.
 *
 * @return bool
 */
function mi_ai_available(): bool {
    return '' === mi_ai_unavailable_reason();
}

/**
 * Explains why "Ask AI" is not usable, or '' when it is. Memoised.
 *
 * Distinguishes the two independent gates:
 *  - the AI Client API itself is missing (WordPress < 7.0, or the AI Building
 *    Blocks are not present); and
 *  - the API is there but no provider can currently serve a text request —
 *    typically no provider key is configured, or (with the AI plugin's
 *    "Connector Approvals" experiment on) this plugin's first real request is
 *    still waiting for administrator approval.
 *
 * @return string Translated reason, or '' when text generation is available.
 */
function mi_ai_unavailable_reason(): string {
    static $reason = null;
    if ( null !== $reason ) {
        return $reason;
    }

    if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
        $reason = __( 'The WordPress AI Client is not available — it ships with WordPress 7.0 and later. "Ask AI" stays hidden until this site is on WordPress 7.0+.', 'modules-insight' );
        return $reason;
    }

    try {
        $supported = (bool) \wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
    } catch ( \Throwable ) {
        $supported = false;
    }

    if ( $supported ) {
        $reason = '';
        return $reason;
    }

    if ( '1' === (string) get_option( 'wpai_feature_connector-approval_enabled' ) ) {
        $reason = __( 'No AI provider is available to Modules Insight yet. Configure a provider in your AI settings, and — because the "Connector Approvals" experiment is on — approve Modules Insight after its first request (the AI plugin shows an approval notice).', 'modules-insight' );
    } else {
        $reason = __( 'No AI provider is configured. Add a provider (Anthropic, Google, or OpenAI) in your AI settings, then reload this page.', 'modules-insight' );
    }
    return $reason;
}

/**
 * Best-guess URL for the site's AI provider settings. The canonical "AI" plugin
 * exposes them at options-general.php?page=ai-wp-admin; returns '' when that
 * can't be determined so callers can omit the link rather than guess wrong.
 *
 * @return string
 */
function mi_ai_admin_url(): string {
    if ( get_option( 'wpai_version' ) ) {
        return admin_url( 'options-general.php?page=ai-wp-admin' );
    }
    return '';
}

/**
 * Builds the grounding context handed to the model for a given plugin slug.
 *
 * @param string $slug       Plugin directory slug.
 * @param string $target_php Target PHP version.
 * @param string $target_wp  Target WordPress version.
 * @return string Plain-text context block, or '' when the slug is unknown.
 */
function mi_ai_build_context( string $slug, string $target_php, string $target_wp ): string {
    $data   = get_plugin_insight_data();
    $plugin = null;
    foreach ( array( 'active', 'inactive' ) as $bucket ) {
        foreach ( $data[ $bucket ] as $p ) {
            if ( explode( '/', $p['path'] )[0] === $slug ) {
                $plugin        = $p;
                $plugin['status'] = ucfirst( $bucket );
                break 2;
            }
        }
    }
    if ( ! $plugin ) {
        return '';
    }

    $lines = array(
        'Site WordPress version: ' . $data['site_info']['wp_version'],
        'Planned upgrade target: PHP ' . $target_php . ', WordPress ' . $target_wp,
        '',
        'Plugin name: ' . $plugin['name'],
        'Installed version: ' . $plugin['version'],
        'Author: ' . wp_strip_all_tags( (string) $plugin['author'] ),
        'Status: ' . $plugin['status'],
        'Description: ' . wp_strip_all_tags( (string) $plugin['description'] ),
    );

    $compat = get_transient( 'mi_compat_' . sanitize_key( $slug ) );
    if ( is_array( $compat ) && empty( $compat['not_found'] ) ) {
        $lines[] = 'WordPress.org last updated: ' . ( $compat['last_updated'] ?: 'unknown' );
        $lines[] = 'WordPress.org "tested up to": ' . ( $compat['tested_up_to'] ?: 'unknown' );
        $lines[] = 'WordPress.org "requires PHP": ' . ( $compat['requires_php'] ?: 'not declared' );
        $lines[] = 'Modules Insight PHP ' . $target_php . ' risk rating: ' . calculate_compat_risk( $compat, $target_php );
        $lines[] = 'Modules Insight WP ' . $target_wp . ' risk rating: ' . calculate_wp_compat_risk( $compat, $target_wp );
    } elseif ( is_array( $compat ) && ! empty( $compat['not_found'] ) ) {
        $lines[] = 'Not found in the WordPress.org plugin directory (premium or custom plugin).';
    } else {
        $lines[] = 'WordPress.org compatibility data has not been fetched for this plugin.';
    }

    $readme = WP_PLUGIN_DIR . '/' . $slug . '/readme.txt';
    if ( is_readable( $readme ) ) {
        $raw = file_get_contents( $readme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( $raw ) {
            $lines[] = '';
            $lines[] = "Plugin's own readme.txt (truncated):";
            $lines[] = mb_substr( wp_strip_all_tags( $raw ), 0, 4000 );
        }
    }

    return implode( "\n", $lines );
}

/**
 * AJAX handler — answers a question about one plugin using the core AI Client.
 */
function ajax_ai_ask() {
    check_ajax_referer( 'modules_insight_compat', 'nonce' );

    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_send_json_error( __( 'Forbidden', 'modules-insight' ), 403 );
    }
    $reason = mi_ai_unavailable_reason();
    if ( '' !== $reason ) {
        wp_send_json_error( $reason );
    }

    // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified via check_ajax_referer() above.
    $slug     = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
    $question = sanitize_textarea_field( wp_unslash( $_POST['question'] ?? '' ) );
    // phpcs:enable WordPress.Security.NonceVerification.Missing
    $question = trim( mb_substr( $question, 0, 500 ) );

    $target_php = mi_sanitize_target( 'target_php', MI_ALLOWED_TARGET_PHP, MI_DEFAULT_TARGET_PHP );
    $target_wp  = mi_sanitize_target( 'target_wp', MI_ALLOWED_TARGET_WP, MI_DEFAULT_TARGET_WP );

    if ( ! $slug || '' === $question ) {
        wp_send_json_error( __( 'A plugin and a question are both required.', 'modules-insight' ) );
    }

    $context = mi_ai_build_context( $slug, $target_php, $target_wp );
    if ( '' === $context ) {
        wp_send_json_error( __( 'Unknown plugin.', 'modules-insight' ) );
    }

    $cache_key = 'mi_ai_' . md5( $slug . '|' . $question . '|' . $target_php . '|' . $target_wp );
    $cached    = get_transient( $cache_key );
    if ( false !== $cached ) {
        wp_send_json_success( array( 'answer' => $cached, 'cached' => true ) );
    }

    $system = 'You are a WordPress upgrade-compatibility assistant. Answer only from the provided '
        . 'context and well-established, long-standing PHP and WordPress facts. Be concise — about '
        . '150 words maximum. Explicitly state when something cannot be determined without testing '
        . 'on a staging environment. Never invent release dates, version numbers, or changelog entries.';

    $prompt = "CONTEXT\n=======\n" . $context . "\n\nQUESTION\n========\n" . $question;

    try {
        $answer = \wp_ai_client_prompt( $prompt )
            ->using_system_instruction( $system )
            ->using_temperature( 0.3 )
            ->using_max_tokens( 600 )
            ->generate_text();
    } catch ( \Throwable ) {
        wp_send_json_error( __( 'The AI request failed. Check the provider configuration under Settings → AI.', 'modules-insight' ) );
    }

    if ( is_wp_error( $answer ) ) {
        wp_send_json_error( $answer->get_error_message() );
    }

    $answer = trim( (string) $answer );
    if ( '' === $answer ) {
        wp_send_json_error( __( 'The AI returned an empty response.', 'modules-insight' ) );
    }

    set_transient( $cache_key, $answer, 12 * HOUR_IN_SECONDS );
    wp_send_json_success( array( 'answer' => $answer, 'cached' => false ) );
}
add_action( 'wp_ajax_modules_insight_ai_ask', __NAMESPACE__ . '\ajax_ai_ask' );
