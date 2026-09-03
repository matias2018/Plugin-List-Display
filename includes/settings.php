<?php
namespace modules_insight;

/**
 * Settings screen (Settings → Modules Insight).
 *
 * Holds the Google Sheet Web App URL + shared secret, and surfaces the status of
 * the WordPress core AI Client used by the "Ask AI" feature.
 *
 * @package modules_insight
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

const MI_OPTION_KEY   = 'modules_insight_options';
const MI_OPTION_GROUP = 'modules_insight_settings';

/**
 * Default option values.
 *
 * @return array
 */
function mi_default_options(): array {
    return array(
        'gsheet_webhook_url' => '',
        'gsheet_token'       => '',
        'gsheet_mode'        => 'append',
    );
}

/**
 * Reads a single plugin option. A wp-config.php constant of the form
 * MODULES_INSIGHT_GSHEET_URL / MODULES_INSIGHT_GSHEET_TOKEN wins over the stored
 * value so secrets can be kept out of the database.
 *
 * @param string $key     Option key.
 * @param mixed  $default  Fallback when unset.
 * @return mixed
 */
function mi_get_option( string $key, $default = '' ) {
    $constants = array(
        'gsheet_webhook_url' => 'MODULES_INSIGHT_GSHEET_URL',
        'gsheet_token'       => 'MODULES_INSIGHT_GSHEET_TOKEN',
    );
    if ( isset( $constants[ $key ] ) && defined( $constants[ $key ] ) ) {
        return constant( $constants[ $key ] );
    }

    $options = get_option( MI_OPTION_KEY, mi_default_options() );
    if ( ! is_array( $options ) ) {
        $options = mi_default_options();
    }
    return $options[ $key ] ?? $default;
}

/**
 * The stored option array, always shaped, ignoring constant overrides.
 *
 * @return array
 */
function mi_raw_options(): array {
    $options = get_option( MI_OPTION_KEY, array() );
    return is_array( $options ) ? array_merge( mi_default_options(), $options ) : mi_default_options();
}

/**
 * True when the webhook URL is locked by a constant (field shown read-only).
 */
function mi_option_is_constant( string $key ): bool {
    $constants = array(
        'gsheet_webhook_url' => 'MODULES_INSIGHT_GSHEET_URL',
        'gsheet_token'       => 'MODULES_INSIGHT_GSHEET_TOKEN',
    );
    return isset( $constants[ $key ] ) && defined( $constants[ $key ] );
}

/**
 * Registers the settings, sections and fields.
 */
function mi_register_settings() {
    register_setting( MI_OPTION_GROUP, MI_OPTION_KEY, array(
        'type'              => 'array',
        'sanitize_callback' => __NAMESPACE__ . '\mi_sanitize_options',
        'default'           => mi_default_options(),
        'autoload'          => false,
    ) );

    add_settings_section(
        'mi_gsheet',
        __( 'Google Sheets export', 'modules-insight' ),
        __NAMESPACE__ . '\mi_gsheet_section_intro',
        'modules-insight'
    );

    add_settings_field( 'gsheet_webhook_url', __( 'Web App URL', 'modules-insight' ), __NAMESPACE__ . '\mi_field_webhook_url', 'modules-insight', 'mi_gsheet' );
    add_settings_field( 'gsheet_token', __( 'Shared secret token', 'modules-insight' ), __NAMESPACE__ . '\mi_field_token', 'modules-insight', 'mi_gsheet' );
    add_settings_field( 'gsheet_mode', __( 'Write mode', 'modules-insight' ), __NAMESPACE__ . '\mi_field_mode', 'modules-insight', 'mi_gsheet' );
}
add_action( 'admin_init', __NAMESPACE__ . '\mi_register_settings' );

/**
 * Sanitises the submitted options. Rejects a webhook URL that is not HTTPS on
 * script.google.com, and never wipes a stored token on an empty submit.
 *
 * @param array $input Raw $_POST values for the option.
 * @return array
 */
function mi_sanitize_options( $input ): array {
    $current = get_option( MI_OPTION_KEY, mi_default_options() );
    if ( ! is_array( $current ) ) {
        $current = mi_default_options();
    }
    $out   = mi_default_options();
    $input = is_array( $input ) ? $input : array();

    // Web App URL.
    $url = isset( $input['gsheet_webhook_url'] ) ? trim( (string) $input['gsheet_webhook_url'] ) : '';
    if ( '' === $url ) {
        $out['gsheet_webhook_url'] = '';
    } else {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) || 'script.google.com' !== $host ) {
            add_settings_error( MI_OPTION_KEY, 'bad_url', __( 'The Web App URL must be an HTTPS address on script.google.com — copy the /exec URL from the Apps Script deployment.', 'modules-insight' ) );
            $out['gsheet_webhook_url'] = $current['gsheet_webhook_url'] ?? '';
        } else {
            $out['gsheet_webhook_url'] = esc_url_raw( $url );
        }
    }

    // Token: blank submit keeps the stored value; the clear checkbox wipes it.
    if ( ! empty( $input['gsheet_token_clear'] ) ) {
        $out['gsheet_token'] = '';
    } elseif ( isset( $input['gsheet_token'] ) && '' !== trim( (string) $input['gsheet_token'] ) ) {
        $out['gsheet_token'] = sanitize_text_field( $input['gsheet_token'] );
    } else {
        $out['gsheet_token'] = $current['gsheet_token'] ?? '';
    }

    // Write mode.
    $mode = isset( $input['gsheet_mode'] ) ? sanitize_key( $input['gsheet_mode'] ) : 'append';
    $out['gsheet_mode'] = in_array( $mode, array( 'append', 'overwrite' ), true ) ? $mode : 'append';

    return $out;
}

function mi_gsheet_section_intro() {
    echo '<p>' . esc_html__( 'Send the compatibility report straight into a Google Sheet instead of downloading a file. One-time setup on the Google side:', 'modules-insight' ) . '</p>';
    echo '<ol>';
    echo '<li>' . esc_html__( 'Open the target Google Sheet → Extensions → Apps Script.', 'modules-insight' ) . '</li>';
    echo '<li>' . esc_html__( 'Replace the editor contents with the script below and set your own SECRET value.', 'modules-insight' ) . '</li>';
    echo '<li>' . esc_html__( 'Deploy → New deployment → type "Web app". Execute as: Me. Who has access: Anyone with the link.', 'modules-insight' ) . '</li>';
    echo '<li>' . esc_html__( 'Copy the Web app URL (ends in /exec) into the field below, and paste the same SECRET into the token field.', 'modules-insight' ) . '</li>';
    echo '</ol>';
    printf(
        '<textarea readonly rows="18" class="large-text code" style="font-family:Menlo,Consolas,monospace;">%s</textarea>',
        esc_textarea( mi_apps_script_source() )
    );
}

function mi_field_webhook_url() {
    $locked = mi_option_is_constant( 'gsheet_webhook_url' );
    printf(
        '<input type="url" class="regular-text" name="%1$s[gsheet_webhook_url]" value="%2$s" placeholder="https://script.google.com/macros/s/…/exec"%3$s>',
        esc_attr( MI_OPTION_KEY ),
        esc_attr( $locked ? mi_get_option( 'gsheet_webhook_url' ) : mi_raw_options()['gsheet_webhook_url'] ),
        $locked ? ' readonly' : ''
    );
    if ( $locked ) {
        echo '<p class="description">' . esc_html__( 'Set by the MODULES_INSIGHT_GSHEET_URL constant in wp-config.php.', 'modules-insight' ) . '</p>';
    }
}

function mi_field_token() {
    if ( mi_option_is_constant( 'gsheet_token' ) ) {
        echo '<p class="description">' . esc_html__( 'Set by the MODULES_INSIGHT_GSHEET_TOKEN constant in wp-config.php.', 'modules-insight' ) . '</p>';
        return;
    }
    $has_token = '' !== (string) mi_raw_options()['gsheet_token'];
    printf(
        '<input type="password" autocomplete="new-password" class="regular-text" name="%1$s[gsheet_token]" value="" placeholder="%2$s">',
        esc_attr( MI_OPTION_KEY ),
        esc_attr( $has_token ? '••••••••  (' . __( 'stored — leave blank to keep', 'modules-insight' ) . ')' : __( 'paste the SECRET from your Apps Script', 'modules-insight' ) )
    );
    if ( $has_token ) {
        printf(
            '<label style="display:block;margin-top:.4em;"><input type="checkbox" name="%s[gsheet_token_clear]" value="1"> %s</label>',
            esc_attr( MI_OPTION_KEY ),
            esc_html__( 'Clear the stored token', 'modules-insight' )
        );
    }
}

function mi_field_mode() {
    $mode = mi_get_option( 'gsheet_mode', 'append' );
    foreach ( array(
        'append'    => __( 'Append — add a new block of rows on each send (keeps history)', 'modules-insight' ),
        'overwrite' => __( 'Overwrite — clear the "Modules Insight" tab first', 'modules-insight' ),
    ) as $value => $label ) {
        printf(
            '<label style="display:block;"><input type="radio" name="%1$s[gsheet_mode]" value="%2$s" %3$s> %4$s</label>',
            esc_attr( MI_OPTION_KEY ),
            esc_attr( $value ),
            checked( $mode, $value, false ),
            esc_html( $label )
        );
    }
}

/**
 * Adds the options page.
 */
function mi_add_settings_page() {
    add_options_page(
        __( 'Modules Insight', 'modules-insight' ),
        __( 'Modules Insight', 'modules-insight' ),
        'manage_options',
        'modules-insight',
        __NAMESPACE__ . '\mi_render_settings_page'
    );
}
add_action( 'admin_menu', __NAMESPACE__ . '\mi_add_settings_page' );

function mi_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Modules Insight', 'modules-insight' ); ?></h1>

        <h2><?php esc_html_e( 'AI plugin Q&A', 'modules-insight' ); ?></h2>
        <?php $ai_reason = mi_ai_unavailable_reason(); ?>
        <?php if ( '' === $ai_reason ) : ?>
            <p><span class="dashicons dashicons-yes" style="color:#155724;"></span>
                <?php esc_html_e( 'Ready — the WordPress AI Client can serve text requests. The "Ask AI" button appears on Medium / High / Not-on-WP.org rows after a compatibility check.', 'modules-insight' ); ?></p>
        <?php else : ?>
            <p><span class="dashicons dashicons-info" style="color:#856404;"></span>
                <?php echo esc_html( $ai_reason ); ?>
                <?php esc_html_e( 'Nothing else in the plugin is affected.', 'modules-insight' ); ?></p>
            <?php $ai_admin_page = mi_ai_admin_url(); ?>
            <?php if ( $ai_admin_page ) : ?>
                <p><a href="<?php echo esc_url( $ai_admin_page ); ?>"><?php esc_html_e( 'Open AI settings →', 'modules-insight' ); ?></a></p>
            <?php endif; ?>
        <?php endif; ?>

        <form action="options.php" method="post">
            <?php
            settings_fields( MI_OPTION_GROUP );
            do_settings_sections( 'modules-insight' );
            submit_button();
            ?>
        </form>
    </div>
    <?php
}
