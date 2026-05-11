<?php
/**
 * Plugin Name: TSE Site Exporter
 * Description: Exports AI-ready structured website intelligence (SEO, content structure, internal/external links, media, CRO signals, schema, interpreted Elementor structure, page classification & hierarchy) as a downloadable ZIP of JSON files.
 * Version:     2.0.0
 * Author:      TSE
 * License:     GPL-2.0-or-later
 * Text Domain: tse-site-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TSE_SITE_EXPORTER_VERSION', '2.0.0' );
define( 'TSE_SITE_EXPORTER_NONCE',   'tse_site_exporter_export' );
define( 'TSE_SITE_EXPORTER_PATH',    plugin_dir_path( __FILE__ ) );

require_once TSE_SITE_EXPORTER_PATH . 'includes/exporter.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/postprocess.php';

/**
 * Admin menu under Tools.
 */
function tse_site_exporter_register_menu() {
    add_management_page(
        __( 'TSE Site Exporter', 'tse-site-exporter' ),
        __( 'TSE Site Exporter', 'tse-site-exporter' ),
        'manage_options',
        'tse-site-exporter',
        'tse_site_exporter_render_admin_page'
    );
}
add_action( 'admin_menu', 'tse_site_exporter_register_menu' );

/**
 * Admin page UI.
 */
function tse_site_exporter_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'tse-site-exporter' ) );
    }

    $action_url = admin_url( 'admin-post.php' );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html__( 'TSE Site Exporter', 'tse-site-exporter' ); ?></h1>
        <p style="max-width:720px">
            <?php echo esc_html__( 'Exports an AI-ready structured intelligence package of this WordPress site: core data, SEO meta (Yoast / Rank Math), content hierarchy, FAQs, internal/external links, media, CRO signals, schema, interpreted Elementor structure, page classification and site hierarchy. Output is a ZIP of JSON files.', 'tse-site-exporter' ); ?>
        </p>

        <form method="post" action="<?php echo esc_url( $action_url ); ?>">
            <input type="hidden" name="action" value="tse_site_exporter_export" />
            <?php wp_nonce_field( TSE_SITE_EXPORTER_NONCE, 'tse_site_exporter_nonce' ); ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Mode', 'tse-site-exporter' ); ?></th>
                        <td>
                            <label>
                                <input type="radio" name="tse_mode" value="quick" checked
                                       data-testid="tse-mode-quick">
                                <?php echo esc_html__( 'Quick (caps at 500 posts; safe for most sites)', 'tse-site-exporter' ); ?>
                            </label><br>
                            <label>
                                <input type="radio" name="tse_mode" value="full"
                                       data-testid="tse-mode-full">
                                <?php echo esc_html__( 'Full (no cap; ensure your PHP memory/timeout can handle it)', 'tse-site-exporter' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__( 'Options', 'tse-site-exporter' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="tse_live_fetch" value="1"
                                       data-testid="tse-opt-live-fetch">
                                <?php echo esc_html__( 'Also fetch the live rendered URL (improves schema/HTML accuracy; slower).', 'tse-site-exporter' ); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="tse_broken_check" value="1"
                                       data-testid="tse-opt-broken-check">
                                <?php echo esc_html__( 'Check internal links for broken targets (HEAD requests; slower).', 'tse-site-exporter' ); ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="tse_include_slices" value="1" checked
                                       data-testid="tse-opt-slices">
                                <?php echo esc_html__( 'Include slice files (seo, internal-links, external-links, cro, schema, elementor, hierarchy, orphans).', 'tse-site-exporter' ); ?>
                            </label>
                        </td>
                    </tr>
                </tbody>
            </table>

            <p>
                <button type="submit"
                        class="button button-primary button-hero"
                        id="tse-site-exporter-button"
                        data-testid="tse-export-site-data-button">
                    <?php echo esc_html__( 'Export Site Data', 'tse-site-exporter' ); ?>
                </button>
            </p>
        </form>
    </div>
    <?php
}

/**
 * Form handler.
 */
function tse_site_exporter_handle_export() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( TSE_SITE_EXPORTER_NONCE, 'tse_site_exporter_nonce' );

    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_die( esc_html__( 'PHP ZipArchive extension is not available. Please enable the PHP zip extension.', 'tse-site-exporter' ) );
    }
    if ( ! class_exists( 'DOMDocument' ) ) {
        wp_die( esc_html__( 'PHP DOM extension is not available. Please enable the PHP dom extension.', 'tse-site-exporter' ) );
    }

    @set_time_limit( 0 );
    @ini_set( 'memory_limit', '512M' );

    $opts = array(
        'mode'           => isset( $_POST['tse_mode'] ) && $_POST['tse_mode'] === 'full' ? 'full' : 'quick',
        'live_fetch'     => ! empty( $_POST['tse_live_fetch'] ),
        'broken_check'   => ! empty( $_POST['tse_broken_check'] ),
        'include_slices' => ! empty( $_POST['tse_include_slices'] ),
        'quick_cap'      => 500,
    );

    $bundle = tse_exporter_run( $opts );

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'tse-site-exporter';
    if ( ! file_exists( $tmp_dir ) ) {
        wp_mkdir_p( $tmp_dir );
    }

    $timestamp = gmdate( 'Ymd-His' );
    $site_slug = sanitize_title( get_bloginfo( 'name' ) );
    if ( empty( $site_slug ) ) {
        $site_slug = 'site';
    }
    $base_name = 'tse-site-export-' . $site_slug . '-' . $timestamp;
    $zip_path  = trailingslashit( $tmp_dir ) . $base_name . '.zip';

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
        wp_die( esc_html__( 'Could not create ZIP archive.', 'tse-site-exporter' ) );
    }

    foreach ( $bundle as $filename => $payload ) {
        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) {
            continue;
        }
        $zip->addFromString( $filename, $json );
    }
    $zip->close();

    while ( ob_get_level() ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $base_name . '.zip"' );
    header( 'Content-Length: ' . filesize( $zip_path ) );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $zip_path );
    @unlink( $zip_path );
    exit;
}
add_action( 'admin_post_tse_site_exporter_export', 'tse_site_exporter_handle_export' );
