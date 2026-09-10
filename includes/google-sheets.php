<?php
namespace modules_insight;

/**
 * Google Sheets export.
 *
 * The plugin does not talk to the Google API directly. The site owner deploys a
 * small Google Apps Script bound to their spreadsheet as a Web App and pastes its
 * URL plus a shared secret token into Settings → Modules Insight. This file POSTs
 * the report to that URL; the script appends the rows.
 *
 * @package modules_insight
 * @since 4.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    die;
}

/**
 * Neutralises spreadsheet formula injection: a cell that begins with =, +, -, @
 * (or a control character) is treated as a formula by Excel and Google Sheets.
 *
 * @param mixed $value Raw cell value.
 * @return string
 */
function mi_cell_safe( $value ): string {
    $value = (string) $value;
    if ( '' !== $value && preg_match( '/^[=+\-@\t\r]/', $value ) ) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Builds the full report as a list of flat rows. Shared by the CSV download, the
 * XLSX download and the Google Sheet push so every export stays identical.
 *
 * @param string $target_php   Target PHP version.
 * @param string $target_wp    Target WordPress version.
 * @param bool   $formula_safe Prefix cells that look like formulas with an
 *                             apostrophe. Needed for CSV (Excel) and Google
 *                             Sheets; skip it for XLSX, whose inline strings are
 *                             never evaluated.
 * @return array[] Ordered rows; the caller writes them verbatim.
 */
function mi_build_report_rows( string $target_php, string $target_wp, bool $formula_safe = true ): array {
    $data  = get_plugin_insight_data();
    $theme = $data['site_info']['active_theme'];
    $rows  = array();

    $safe = $formula_safe
        ? __NAMESPACE__ . '\mi_cell_safe'
        : 'strval';

    $rows[] = array( 'Modules Insight report', current_time( 'mysql' ), home_url() );
    $rows[] = array( 'WordPress version', $data['site_info']['wp_version'] );
    $rows[] = array_map( $safe, array( 'Active theme', $theme['name'], $theme['version'], $theme['author'], $theme['theme_uri'] ) );
    $rows[] = array( 'Target PHP', $target_php, 'Target WP', $target_wp );
    $rows[] = array( '' ); // Spacer. Not array() — Apps Script's appendRow() rejects an empty array.

    $rows[] = array(
        'Status', 'Name', 'Version', 'Path', 'Author', 'Plugin URI', 'Author URI',
        'Network Active', 'Last Updated (WP.org)', 'Tested up to (WP)', 'Min PHP',
        'PHP ' . $target_php . ' Risk', 'WP ' . $target_wp . ' Risk',
    );

    foreach ( array( 'active' => 'Active', 'inactive' => 'Inactive' ) as $bucket => $status_label ) {
        foreach ( $data[ $bucket ] as $plugin ) {
            $compat = get_compat_export_data( explode( '/', $plugin['path'] )[0], $target_php, $target_wp );
            $rows[] = array_map( $safe, array(
                $status_label,
                $plugin['name'],
                $plugin['version'],
                $plugin['path'],
                $plugin['author'],
                $plugin['plugin_uri'],
                $plugin['author_uri'],
                ( 'active' === $bucket && $plugin['network'] ) ? 'Yes' : 'No',
                $compat['last_updated'] ?? $compat['status'],
                $compat['tested_up_to'] ?? '',
                $compat['requires_php'] ?? '',
                $compat['risk']         ?? $compat['status'],
                $compat['wp_risk']      ?? $compat['status'],
            ) );
        }
    }

    return $rows;
}

/**
 * POSTs the report rows to the configured Apps Script Web App.
 *
 * @param array[] $rows Rows from mi_build_report_rows().
 * @return true|\WP_Error
 */
function mi_send_to_gsheet( array $rows ) {
    $url   = mi_get_option( 'gsheet_webhook_url' );
    $token = mi_get_option( 'gsheet_token' );

    if ( ! $url ) {
        return new \WP_Error( 'mi_no_url', __( 'No Google Sheet Web App URL is configured.', 'modules-insight' ) );
    }

    $response = wp_remote_post( $url, array(
        'timeout'     => 20,
        // Apps Script answers with a 302 to script.googleusercontent.com, and
        // that endpoint only accepts GET/HEAD. WordPress's HTTP client re-issues
        // a 302 as another POST, which is rejected (405) and the JSON body is
        // lost. Stop at the redirect and fetch the destination ourselves below.
        'redirection' => 0,
        'headers'     => array( 'Content-Type' => 'application/json' ),
        'body'        => wp_json_encode( array(
            'token'     => $token,
            'mode'      => mi_get_option( 'gsheet_mode', 'append' ),
            'generated' => current_time( 'mysql' ),
            'site'      => home_url(),
            'rows'      => $rows,
        ) ),
    ) );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $code = (int) wp_remote_retrieve_response_code( $response );

    for ( $hop = 0; $hop < 3 && $code >= 300 && $code < 400; $hop++ ) {
        $location = wp_remote_retrieve_header( $response, 'location' );
        if ( ! $location ) {
            return new \WP_Error( 'mi_gsheet_failed', __( 'The Google Sheet redirected without a destination.', 'modules-insight' ) );
        }
        $response = wp_remote_get( $location, array( 'timeout' => 20, 'redirection' => 0 ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( 200 !== (int) $code || ! is_array( $body ) || empty( $body['ok'] ) ) {
        $detail = is_array( $body ) && ! empty( $body['error'] )
            ? $body['error']
            : sprintf( /* translators: %d: HTTP status code */ __( 'HTTP %d', 'modules-insight' ), (int) $code );
        return new \WP_Error( 'mi_gsheet_failed', sprintf(
            /* translators: %s: error detail from the Apps Script */
            __( 'The Google Sheet rejected the request: %s', 'modules-insight' ),
            $detail
        ) );
    }

    return true;
}

/**
 * AJAX handler — builds the report and pushes it to the configured Google Sheet.
 */
function ajax_push_gsheet() {
    check_ajax_referer( 'modules_insight_compat', 'nonce' );

    if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_send_json_error( __( 'Forbidden', 'modules-insight' ), 403 );
    }

    $target_php = mi_sanitize_target( 'target_php', MI_ALLOWED_TARGET_PHP, MI_DEFAULT_TARGET_PHP );
    $target_wp  = mi_sanitize_target( 'target_wp', MI_ALLOWED_TARGET_WP, MI_DEFAULT_TARGET_WP );

    $result = mi_send_to_gsheet( mi_build_report_rows( $target_php, $target_wp ) );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( $result->get_error_message() );
    }

    wp_send_json_success( array(
        'message' => __( 'Report sent to Google Sheet.', 'modules-insight' ),
    ) );
}
add_action( 'wp_ajax_modules_insight_push_gsheet', __NAMESPACE__ . '\ajax_push_gsheet' );

/**
 * The Apps Script the site owner pastes into their spreadsheet. Rendered on the
 * settings page for copy/paste and kept here so there is a single source of truth.
 *
 * @return string
 */
function mi_apps_script_source(): string {
    return <<<'JS'
const SECRET = 'CHANGE_ME'; // must match the token you paste into Modules Insight

function doPost(e) {
  const out = v => ContentService
    .createTextOutput(JSON.stringify(v))
    .setMimeType(ContentService.MimeType.JSON);

  let data;
  try {
    data = JSON.parse(e.postData.contents);
  } catch (err) {
    return out({ ok: false, error: 'bad json' });
  }

  if (data.token !== SECRET) {
    return out({ ok: false, error: 'bad token' });
  }

  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const sheet = ss.getSheetByName('Modules Insight') || ss.insertSheet('Modules Insight');
  if (data.mode === 'overwrite') {
    sheet.clearContents();
  }
  (data.rows || []).forEach(row => sheet.appendRow(row.length ? row : ['']));

  return out({ ok: true, written: (data.rows || []).length });
}
JS;
}
