<?php
/**
 * TSE Site Exporter — Dashboard layer (V2.8.0).
 *
 * Lightweight operational dashboard inside the existing WP admin page.
 * No React, no framework. Pure WP admin + a tiny option-backed history.
 *
 * Responsibilities:
 *   - Persist export / AI-analysis runs (history + the produced ZIPs).
 *   - Render history table (date, type, provider, model, status).
 *   - Render "Recent Reports" panels grouped by:
 *       Exports, AI Reports, Internal Link Reports, Cluster Reports, Raw JSON.
 *   - Serve individual files OUT of the stored ZIPs (inline or attachment),
 *     letting HTML reports be opened directly in an iframe panel inside
 *     wp-admin — no manual ZIP browsing.
 *
 * Storage:
 *   - ZIPs live in wp-content/uploads/tse-site-exporter/ (already created
 *     by the export pipeline). We simply STOP unlinking them after stream.
 *   - Run history lives in wp_options under `tse_site_exporter_runs`.
 *     Capped at TSE_RUNS_MAX entries; pruned ZIPs are removed from disk.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TSE_RUNS_OPTION', 'tse_site_exporter_runs' );
define( 'TSE_RUNS_MAX',    50 );

/* -------------------------------------------------------------------------
 * Storage / history primitives
 * ---------------------------------------------------------------------- */

function tse_dashboard_storage_dir() {
    $u   = wp_upload_dir();
    $dir = trailingslashit( $u['basedir'] ) . 'tse-site-exporter';
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    // Defence in depth — the dashboard streams files through admin-post.php
    // (capability checked) so no static path access should leak data.
    $ht = trailingslashit( $dir ) . '.htaccess';
    if ( ! file_exists( $ht ) ) {
        @file_put_contents( $ht, "Deny from all\n" );
    }
    $idx = trailingslashit( $dir ) . 'index.html';
    if ( ! file_exists( $idx ) ) {
        @file_put_contents( $idx, '' );
    }
    return $dir;
}

function tse_dashboard_get_runs() {
    $runs = get_option( TSE_RUNS_OPTION, array() );
    return is_array( $runs ) ? $runs : array();
}

function tse_dashboard_save_runs( $runs ) {
    update_option( TSE_RUNS_OPTION, array_values( $runs ), false );
}

/**
 * Append a run entry. Newest-first; oldest beyond TSE_RUNS_MAX are pruned
 * with their ZIPs.
 */
function tse_dashboard_record_run( $entry ) {
    $entry = array_merge(
        array(
            'id'           => 'r_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 6, false, false ),
            'type'         => 'export',
            'status'       => 'success',
            'message'      => '',
            'started_at'   => gmdate( 'c' ),
            'finished_at'  => gmdate( 'c' ),
            'provider'     => '',
            'provider_label' => '',
            'model'        => '',
            'export_type'  => '',
            'zip_path'     => '',
            'zip_name'     => '',
            'size'         => 0,
            'files'        => array(),
        ),
        $entry
    );

    $runs = tse_dashboard_get_runs();
    array_unshift( $runs, $entry );

    if ( count( $runs ) > TSE_RUNS_MAX ) {
        $dropped = array_slice( $runs, TSE_RUNS_MAX );
        foreach ( $dropped as $d ) {
            tse_dashboard_delete_run_files( $d );
        }
        $runs = array_slice( $runs, 0, TSE_RUNS_MAX );
    }

    tse_dashboard_save_runs( $runs );
    return $entry;
}

function tse_dashboard_find_run( $id ) {
    foreach ( tse_dashboard_get_runs() as $r ) {
        if ( isset( $r['id'] ) && $r['id'] === $id ) {
            return $r;
        }
    }
    return null;
}

function tse_dashboard_delete_run_files( $entry ) {
    if ( ! empty( $entry['zip_path'] ) && file_exists( $entry['zip_path'] ) ) {
        @unlink( $entry['zip_path'] );
    }
}

function tse_dashboard_delete_run( $id ) {
    $runs = tse_dashboard_get_runs();
    $out  = array();
    foreach ( $runs as $r ) {
        if ( isset( $r['id'] ) && $r['id'] === $id ) {
            tse_dashboard_delete_run_files( $r );
            continue;
        }
        $out[] = $r;
    }
    tse_dashboard_save_runs( $out );
}

/* -------------------------------------------------------------------------
 * File categorisation (drives the "Recent Reports" grouping)
 * ---------------------------------------------------------------------- */

function tse_dashboard_file_category( $filename ) {
    $f = strtolower( (string) $filename );
    if ( $f === 'ai-report.html' )            return 'ai';
    if ( $f === 'internal-link-report.html' ) return 'internal_links';
    if ( $f === 'cluster-report.html' )       return 'cluster';
    if ( substr( $f, -5 ) === '.html' )       return 'ai';
    if ( substr( $f, -5 ) === '.json' )       return 'json';
    return 'other';
}

function tse_dashboard_category_label( $cat ) {
    $labels = array(
        'ai'             => __( 'AI Reports', 'tse-site-exporter' ),
        'internal_links' => __( 'Internal Link Reports', 'tse-site-exporter' ),
        'cluster'        => __( 'Cluster Reports', 'tse-site-exporter' ),
        'json'           => __( 'Raw JSON', 'tse-site-exporter' ),
        'other'          => __( 'Other', 'tse-site-exporter' ),
    );
    return isset( $labels[ $cat ] ) ? $labels[ $cat ] : ucfirst( $cat );
}

function tse_dashboard_categorise_files( $files ) {
    $groups = array( 'ai' => array(), 'internal_links' => array(), 'cluster' => array(), 'json' => array(), 'other' => array() );
    foreach ( (array) $files as $f ) {
        $groups[ tse_dashboard_file_category( $f ) ][] = $f;
    }
    return $groups;
}

/* -------------------------------------------------------------------------
 * URL helpers
 * ---------------------------------------------------------------------- */

function tse_dashboard_serve_url( $run_id, $file, $disposition = 'inline' ) {
    return add_query_arg(
        array(
            'action'      => 'tse_site_exporter_serve',
            'run'         => rawurlencode( $run_id ),
            'file'        => rawurlencode( $file ),
            'disposition' => $disposition,
            '_wpnonce'    => wp_create_nonce( 'tse_serve_' . $run_id ),
        ),
        admin_url( 'admin-post.php' )
    );
}

function tse_dashboard_zip_download_url( $run_id ) {
    return add_query_arg(
        array(
            'action'   => 'tse_site_exporter_download_zip',
            'run'      => rawurlencode( $run_id ),
            '_wpnonce' => wp_create_nonce( 'tse_zip_' . $run_id ),
        ),
        admin_url( 'admin-post.php' )
    );
}

function tse_dashboard_viewer_url( $run_id, $file ) {
    return add_query_arg(
        array(
            'page' => 'tse-site-exporter',
            'view' => 'run',
            'run'  => rawurlencode( $run_id ),
            'file' => rawurlencode( $file ),
        ),
        admin_url( 'tools.php' )
    );
}

function tse_dashboard_delete_url( $run_id ) {
    return wp_nonce_url(
        add_query_arg(
            array(
                'action' => 'tse_site_exporter_delete_run',
                'run'    => rawurlencode( $run_id ),
            ),
            admin_url( 'admin-post.php' )
        ),
        'tse_delete_' . $run_id
    );
}

/* -------------------------------------------------------------------------
 * Rendering
 * ---------------------------------------------------------------------- */

function tse_dashboard_format_local_time( $iso ) {
    if ( empty( $iso ) ) return '';
    $ts = strtotime( $iso );
    if ( ! $ts ) return esc_html( $iso );
    return esc_html( date_i18n( 'Y-m-d H:i', $ts ) );
}

function tse_dashboard_render() {
    $runs = tse_dashboard_get_runs();

    echo '<hr style="margin:32px 0">';
    echo '<h2 data-testid="tse-dashboard-heading">' . esc_html__( 'Dashboard — recent activity', 'tse-site-exporter' ) . '</h2>';
    echo '<p style="max-width:720px;color:#555">' . esc_html__( 'Every export and AI analysis is stored here so previous runs stay accessible after refresh. Open HTML reports directly inside wp-admin — no need to unzip anything by hand.', 'tse-site-exporter' ) . '</p>';

    tse_dashboard_render_history( $runs );
    tse_dashboard_render_recent_reports( $runs );
}

function tse_dashboard_render_history( $runs ) {
    echo '<h3 style="margin-top:24px">' . esc_html__( 'Export / Analysis history', 'tse-site-exporter' ) . '</h3>';

    if ( empty( $runs ) ) {
        echo '<p style="color:#646970"><em>' . esc_html__( 'No runs yet. Trigger an export or AI analysis above — entries will appear here.', 'tse-site-exporter' ) . '</em></p>';
        return;
    }

    echo '<table class="widefat striped" data-testid="tse-dashboard-history" style="max-width:1100px">';
    echo '<thead><tr>'
        . '<th>' . esc_html__( 'When', 'tse-site-exporter' ) . '</th>'
        . '<th>' . esc_html__( 'Type', 'tse-site-exporter' ) . '</th>'
        . '<th>' . esc_html__( 'Provider', 'tse-site-exporter' ) . '</th>'
        . '<th>' . esc_html__( 'Model', 'tse-site-exporter' ) . '</th>'
        . '<th>' . esc_html__( 'Detail', 'tse-site-exporter' ) . '</th>'
        . '<th>' . esc_html__( 'Status', 'tse-site-exporter' ) . '</th>'
        . '<th>' . esc_html__( 'Actions', 'tse-site-exporter' ) . '</th>'
        . '</tr></thead><tbody>';

    foreach ( $runs as $r ) {
        $type_label = 'export' === $r['type'] ? __( 'Export', 'tse-site-exporter' ) : __( 'AI Analysis', 'tse-site-exporter' );
        $status_ok  = 'success' === $r['status'];
        $status_color = $status_ok ? '#1d7f3a' : '#b32d2e';
        $status_text  = $status_ok ? __( 'Success', 'tse-site-exporter' ) : __( 'Failure', 'tse-site-exporter' );

        $detail = '';
        if ( 'export' === $r['type'] ) {
            $detail = ! empty( $r['export_type'] ) ? sprintf( '%s mode', $r['export_type'] ) : '';
        } else {
            $detail = ! empty( $r['files'] ) ? sprintf( __( '%d files', 'tse-site-exporter' ), count( $r['files'] ) ) : '';
        }

        $can_view = $status_ok && ! empty( $r['zip_path'] ) && file_exists( $r['zip_path'] );

        echo '<tr data-testid="tse-history-row-' . esc_attr( $r['id'] ) . '">';
        echo '<td>' . tse_dashboard_format_local_time( $r['finished_at'] ) . '</td>';
        echo '<td>' . esc_html( $type_label ) . '</td>';
        echo '<td>' . esc_html( $r['provider_label'] ?: $r['provider'] ?: '—' ) . '</td>';
        echo '<td>' . esc_html( $r['model'] ?: '—' ) . '</td>';
        echo '<td>' . esc_html( $detail ?: '—' );
        if ( ! $status_ok && ! empty( $r['message'] ) ) {
            echo '<br><small style="color:#b32d2e">' . esc_html( $r['message'] ) . '</small>';
        }
        echo '</td>';
        echo '<td style="color:' . esc_attr( $status_color ) . ';font-weight:600">' . esc_html( $status_text ) . '</td>';
        echo '<td>';
        if ( $can_view ) {
            echo '<a href="' . esc_url( tse_dashboard_zip_download_url( $r['id'] ) ) . '" class="button button-small" data-testid="tse-history-download-' . esc_attr( $r['id'] ) . '">' . esc_html__( 'ZIP', 'tse-site-exporter' ) . '</a> ';
        }
        echo '<a href="' . esc_url( tse_dashboard_delete_url( $r['id'] ) ) . '" class="button button-small" '
            . 'onclick="return confirm(\'' . esc_js( __( 'Delete this run and its files?', 'tse-site-exporter' ) ) . '\');" '
            . 'data-testid="tse-history-delete-' . esc_attr( $r['id'] ) . '">'
            . esc_html__( 'Delete', 'tse-site-exporter' ) . '</a>';
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

function tse_dashboard_render_recent_reports( $runs ) {
    echo '<h3 style="margin-top:32px">' . esc_html__( 'Recent reports', 'tse-site-exporter' ) . '</h3>';

    // We pull the latest successful run of each type.
    $latest_ai     = null;
    $latest_export = null;
    foreach ( $runs as $r ) {
        if ( 'success' !== $r['status'] || empty( $r['zip_path'] ) || ! file_exists( $r['zip_path'] ) ) continue;
        if ( ! $latest_ai     && 'ai'     === $r['type'] ) $latest_ai     = $r;
        if ( ! $latest_export && 'export' === $r['type'] ) $latest_export = $r;
        if ( $latest_ai && $latest_export ) break;
    }

    if ( ! $latest_ai && ! $latest_export ) {
        echo '<p style="color:#646970"><em>' . esc_html__( 'Nothing to open yet. Run an export or AI analysis above.', 'tse-site-exporter' ) . '</em></p>';
        return;
    }

    echo '<div style="display:flex;flex-wrap:wrap;gap:24px;margin-top:8px">';

    tse_dashboard_render_panel(
        __( 'Exports', 'tse-site-exporter' ),
        $latest_export,
        array( 'json' )
    );
    tse_dashboard_render_panel(
        __( 'AI Analysis', 'tse-site-exporter' ),
        $latest_ai,
        array( 'ai', 'internal_links', 'cluster', 'json' )
    );

    echo '</div>';
}

function tse_dashboard_render_panel( $title, $run, $show_categories ) {
    echo '<div style="flex:1 1 420px;min-width:380px;border:1px solid #dcdcde;border-radius:4px;padding:16px;background:#fff">';
    echo '<h4 style="margin:0 0 4px 0">' . esc_html( $title ) . '</h4>';

    if ( ! $run ) {
        echo '<p style="color:#646970"><em>' . esc_html__( 'No successful run yet.', 'tse-site-exporter' ) . '</em></p>';
        echo '</div>';
        return;
    }

    echo '<p style="margin:0 0 12px 0;color:#646970;font-size:12px">'
        . esc_html__( 'Latest run:', 'tse-site-exporter' ) . ' '
        . tse_dashboard_format_local_time( $run['finished_at'] );
    if ( ! empty( $run['provider_label'] ) || ! empty( $run['model'] ) ) {
        echo ' · ' . esc_html( trim( ( $run['provider_label'] ?: $run['provider'] ) . ' ' . $run['model'] ) );
    }
    echo '</p>';

    // Download ZIP button always visible.
    echo '<p style="margin:0 0 12px 0">'
        . '<a href="' . esc_url( tse_dashboard_zip_download_url( $run['id'] ) ) . '" class="button button-primary" data-testid="tse-panel-download-zip-' . esc_attr( $run['id'] ) . '">'
        . esc_html__( 'Download Full ZIP', 'tse-site-exporter' ) . '</a>';
    echo '</p>';

    $groups = tse_dashboard_categorise_files( $run['files'] );

    foreach ( $show_categories as $cat ) {
        if ( empty( $groups[ $cat ] ) ) continue;
        echo '<div style="margin-top:12px">';
        echo '<strong>' . esc_html( tse_dashboard_category_label( $cat ) ) . '</strong>';
        echo '<ul style="margin:4px 0 0 0;padding:0;list-style:none">';
        foreach ( $groups[ $cat ] as $file ) {
            $is_html = substr( strtolower( $file ), -5 ) === '.html';
            if ( $is_html ) {
                $url   = tse_dashboard_viewer_url( $run['id'], $file );
                $label = sprintf( __( 'Open %s', 'tse-site-exporter' ), $file );
            } else {
                $url   = tse_dashboard_serve_url( $run['id'], $file, 'inline' );
                $label = sprintf( __( 'View %s', 'tse-site-exporter' ), $file );
            }
            echo '<li style="margin:2px 0">';
            echo '<a href="' . esc_url( $url ) . '"';
            if ( ! $is_html ) echo ' target="_blank" rel="noopener"';
            echo ' data-testid="tse-panel-file-' . esc_attr( $run['id'] . '-' . sanitize_title( $file ) ) . '">'
                . esc_html( $label ) . '</a>';
            echo '</li>';
        }
        echo '</ul></div>';
    }

    echo '</div>';
}

/* -------------------------------------------------------------------------
 * Iframe viewer (lives inside wp-admin)
 * ---------------------------------------------------------------------- */

function tse_dashboard_render_viewer( $run_id, $file ) {
    $run = tse_dashboard_find_run( $run_id );
    if ( ! $run || empty( $run['files'] ) || ! in_array( $file, (array) $run['files'], true ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Report not found.', 'tse-site-exporter' ) . '</p></div>';
        echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=tse-site-exporter' ) ) . '" data-testid="tse-viewer-back">'
             . esc_html__( '← Back to dashboard', 'tse-site-exporter' ) . '</a></p>';
        return;
    }

    $src = tse_dashboard_serve_url( $run_id, $file, 'inline' );
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin:12px 0">
        <div>
            <a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=tse-site-exporter' ) ); ?>" data-testid="tse-viewer-back">
                <?php echo esc_html__( '← Back to dashboard', 'tse-site-exporter' ); ?>
            </a>
            <span style="margin-left:12px;color:#646970"><?php echo esc_html( $file ); ?></span>
        </div>
        <div>
            <a class="button" href="<?php echo esc_url( tse_dashboard_serve_url( $run_id, $file, 'inline' ) ); ?>" target="_blank" rel="noopener" data-testid="tse-viewer-open-new">
                <?php echo esc_html__( 'Open in new tab', 'tse-site-exporter' ); ?>
            </a>
            <a class="button" href="<?php echo esc_url( tse_dashboard_zip_download_url( $run_id ) ); ?>" data-testid="tse-viewer-download-zip">
                <?php echo esc_html__( 'Download ZIP', 'tse-site-exporter' ); ?>
            </a>
        </div>
    </div>
    <iframe
        src="<?php echo esc_url( $src ); ?>"
        style="width:100%;height:78vh;border:1px solid #dcdcde;border-radius:4px;background:#fff"
        data-testid="tse-viewer-iframe"></iframe>
    <?php
}

/* -------------------------------------------------------------------------
 * Admin-post handlers: serve a single file out of a stored ZIP
 * ---------------------------------------------------------------------- */

function tse_dashboard_handle_serve() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }

    $run_id = isset( $_GET['run'] )  ? sanitize_text_field( wp_unslash( $_GET['run'] ) )  : '';
    $file   = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
    $disp   = isset( $_GET['disposition'] ) && 'attachment' === $_GET['disposition'] ? 'attachment' : 'inline';
    $nonce  = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';

    if ( ! wp_verify_nonce( $nonce, 'tse_serve_' . $run_id ) ) {
        wp_die( esc_html__( 'Invalid request.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }

    $run = tse_dashboard_find_run( $run_id );
    if ( ! $run || empty( $run['zip_path'] ) || ! file_exists( $run['zip_path'] ) ) {
        wp_die( esc_html__( 'Run not found.', 'tse-site-exporter' ), '', array( 'response' => 404 ) );
    }
    // Allow-list: only files we recorded at creation time.
    if ( ! in_array( $file, (array) $run['files'], true ) ) {
        wp_die( esc_html__( 'File not in run manifest.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }

    $zip = new ZipArchive();
    if ( true !== $zip->open( $run['zip_path'] ) ) {
        wp_die( esc_html__( 'Could not open ZIP archive.', 'tse-site-exporter' ), '', array( 'response' => 500 ) );
    }
    $contents = $zip->getFromName( $file );
    $zip->close();
    if ( false === $contents ) {
        wp_die( esc_html__( 'File not found inside ZIP.', 'tse-site-exporter' ), '', array( 'response' => 404 ) );
    }

    $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
    $mime_map = array(
        'html' => 'text/html; charset=UTF-8',
        'htm'  => 'text/html; charset=UTF-8',
        'json' => 'application/json; charset=UTF-8',
        'txt'  => 'text/plain; charset=UTF-8',
        'csv'  => 'text/csv; charset=UTF-8',
    );
    $mime = isset( $mime_map[ $ext ] ) ? $mime_map[ $ext ] : 'application/octet-stream';

    while ( ob_get_level() ) { ob_end_clean(); }
    nocache_headers();
    header( 'Content-Type: ' . $mime );
    header( 'Content-Length: ' . strlen( $contents ) );
    header( 'X-Content-Type-Options: nosniff' );
    if ( 'attachment' === $disp ) {
        header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
    } else {
        header( 'Content-Disposition: inline; filename="' . basename( $file ) . '"' );
    }
    echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — verbatim file payload, mime declared above.
    exit;
}
add_action( 'admin_post_tse_site_exporter_serve', 'tse_dashboard_handle_serve' );

function tse_dashboard_handle_download_zip() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }
    $run_id = isset( $_GET['run'] ) ? sanitize_text_field( wp_unslash( $_GET['run'] ) ) : '';
    $nonce  = isset( $_GET['_wpnonce'] ) ? (string) $_GET['_wpnonce'] : '';
    if ( ! wp_verify_nonce( $nonce, 'tse_zip_' . $run_id ) ) {
        wp_die( esc_html__( 'Invalid request.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }
    $run = tse_dashboard_find_run( $run_id );
    if ( ! $run || empty( $run['zip_path'] ) || ! file_exists( $run['zip_path'] ) ) {
        wp_die( esc_html__( 'Run not found.', 'tse-site-exporter' ), '', array( 'response' => 404 ) );
    }

    while ( ob_get_level() ) { ob_end_clean(); }
    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . ( $run['zip_name'] ?: basename( $run['zip_path'] ) ) . '"' );
    header( 'Content-Length: ' . filesize( $run['zip_path'] ) );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $run['zip_path'] );
    exit;
}
add_action( 'admin_post_tse_site_exporter_download_zip', 'tse_dashboard_handle_download_zip' );

function tse_dashboard_handle_delete_run() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }
    $run_id = isset( $_REQUEST['run'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['run'] ) ) : '';
    $nonce  = isset( $_REQUEST['_wpnonce'] ) ? (string) $_REQUEST['_wpnonce'] : '';
    if ( ! wp_verify_nonce( $nonce, 'tse_delete_' . $run_id ) ) {
        wp_die( esc_html__( 'Invalid request.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }
    tse_dashboard_delete_run( $run_id );

    wp_safe_redirect( add_query_arg( array(
        'page'           => 'tse-site-exporter',
        'tse_run_deleted'=> '1',
    ), admin_url( 'tools.php' ) ) );
    exit;
}
add_action( 'admin_post_tse_site_exporter_delete_run', 'tse_dashboard_handle_delete_run' );
