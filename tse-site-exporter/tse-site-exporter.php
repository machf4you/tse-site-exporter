<?php
/**
 * Plugin Name: TSE Site Exporter
 * Description: Exports AI-ready structured website intelligence (SEO, content hierarchy, internal/external links, media, CRO signals, full structured-data audit, interpreted Elementor structure, page classification, site hierarchy, and a full internal-link relationship graph with per-page metrics, orphan/weak detection, classification flow and top hubs/authorities) as a downloadable ZIP of JSON files.
 * Version:     2.10.0
 * Author:      TSE
 * License:     GPL-2.0-or-later
 * Text Domain: tse-site-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TSE_SITE_EXPORTER_VERSION', '2.10.0' );
define( 'TSE_SITE_EXPORTER_NONCE',   'tse_site_exporter_export' );
define( 'TSE_SITE_EXPORTER_AI_NONCE','tse_site_exporter_ai' );
define( 'TSE_SITE_EXPORTER_PATH',    plugin_dir_path( __FILE__ ) );

require_once TSE_SITE_EXPORTER_PATH . 'includes/schema.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/exporter.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/postprocess.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/relationships.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/authority.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/ai_summary.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/ai_settings.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/ai_provider.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/ai_runner.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/ai_report.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/page_intent.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/issue_normaliser.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/strategy.php';
require_once TSE_SITE_EXPORTER_PATH . 'includes/dashboard.php';

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

    // Internal viewer: open a single HTML report (from a stored run) inside
    // an iframe panel so users don't have to manually browse the ZIP.
    if ( isset( $_GET['view'] ) && 'run' === $_GET['view'] ) {
        $run_id = isset( $_GET['run'] )  ? sanitize_text_field( wp_unslash( $_GET['run'] ) )  : '';
        $file   = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__( 'TSE Site Exporter — Report viewer', 'tse-site-exporter' ) . '</h1>';
        tse_dashboard_render_viewer( $run_id, $file );
        echo '</div>';
        return;
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
                                <input type="checkbox" name="tse_live_fetch" value="1" checked
                                       data-testid="tse-opt-live-fetch">
                                <?php echo esc_html__( 'Fetch live rendered URL for accurate schema extraction (recommended — many SEO plugins inject JSON-LD into wp_head, not the post content).', 'tse-site-exporter' ); ?>
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

        <?php tse_site_exporter_render_ai_section(); ?>

        <?php tse_strategy_render_admin_section(); ?>

        <?php tse_dashboard_render(); ?>
    </div>
    <?php
}

/**
 * AI Analysis Execution Layer (V2.5) — settings + run button.
 */
function tse_site_exporter_render_ai_section() {
    $settings    = tse_ai_get_settings();
    $providers   = tse_ai_supported_providers();
    $const_map   = array(
        'openai'    => array( 'TSE_OPENAI_KEY', 'TSE_OPENAI_MODEL' ),
        'anthropic' => array( 'TSE_ANTHROPIC_KEY', 'TSE_ANTHROPIC_MODEL' ),
        'gemini'    => array( 'TSE_GEMINI_KEY', 'TSE_GEMINI_MODEL' ),
    );
    $action_url = admin_url( 'admin-post.php' );
    ?>
    <hr style="margin:32px 0">
    <h2 style="display:flex;align-items:center;gap:8px">
        <?php echo esc_html__( 'AI Analysis (V2.5)', 'tse-site-exporter' ); ?>
        <span style="font-size:12px;color:#646970;font-weight:normal">
            <?php echo esc_html__( 'Optional. Uses the compact AI-ready datasets only — no Elementor or raw HTML is sent to any LLM.', 'tse-site-exporter' ); ?>
        </span>
    </h2>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>">
        <input type="hidden" name="action" value="tse_site_exporter_ai_save" />
        <?php wp_nonce_field( TSE_SITE_EXPORTER_AI_NONCE, 'tse_site_exporter_ai_nonce' ); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Default provider', 'tse-site-exporter' ); ?></th>
                    <td>
                        <select name="provider" data-testid="tse-ai-provider-select">
                            <?php foreach ( $providers as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['provider'], $slug ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <?php foreach ( $providers as $slug => $label ) :
                    $const_key   = $const_map[ $slug ][0];
                    $const_model = $const_map[ $slug ][1];
                    $key_set_via_const   = defined( $const_key );
                    $model_set_via_const = defined( $const_model );
                    $stored_key   = isset( $settings[ $slug . '_key' ] ) ? $settings[ $slug . '_key' ] : '';
                    $stored_model = isset( $settings[ $slug . '_model' ] ) ? $settings[ $slug . '_model' ] : '';
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $label ); ?></th>
                    <td>
                        <p>
                            <label style="display:block;margin-bottom:4px">
                                <?php echo esc_html__( 'API key', 'tse-site-exporter' ); ?>
                            </label>
                            <?php if ( $key_set_via_const ) : ?>
                                <code><?php echo esc_html( $const_key ); ?></code>
                                <?php echo esc_html__( 'is set in wp-config.php. UI field disabled.', 'tse-site-exporter' ); ?>
                            <?php else : ?>
                                <input type="password"
                                       class="regular-text"
                                       name="<?php echo esc_attr( $slug ); ?>_key"
                                       autocomplete="new-password"
                                       placeholder="<?php echo $stored_key ? esc_attr( tse_ai_mask_key( $stored_key ) ) : ''; ?>"
                                       data-testid="tse-ai-<?php echo esc_attr( $slug ); ?>-key" />
                                <span class="description">
                                    <?php echo $stored_key ? esc_html__( 'Leave blank to keep current key.', 'tse-site-exporter' ) : esc_html__( 'Not configured.', 'tse-site-exporter' ); ?>
                                </span>
                            <?php endif; ?>
                        </p>
                        <p>
                            <label style="display:block;margin-bottom:4px">
                                <?php echo esc_html__( 'Model', 'tse-site-exporter' ); ?>
                            </label>
                            <?php if ( $model_set_via_const ) : ?>
                                <code><?php echo esc_html( $const_model ); ?></code>
                                <?php echo esc_html__( 'is set in wp-config.php. UI field disabled.', 'tse-site-exporter' ); ?>
                            <?php else : ?>
                                <input type="text"
                                       class="regular-text"
                                       name="<?php echo esc_attr( $slug ); ?>_model"
                                       value="<?php echo esc_attr( $stored_model ); ?>"
                                       data-testid="tse-ai-<?php echo esc_attr( $slug ); ?>-model" />
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="submit" class="button" data-testid="tse-ai-save-settings">
                <?php echo esc_html__( 'Save AI Settings', 'tse-site-exporter' ); ?>
            </button>
        </p>
    </form>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top:16px">
        <input type="hidden" name="action" value="tse_site_exporter_ai_run" />
        <?php wp_nonce_field( TSE_SITE_EXPORTER_AI_NONCE, 'tse_site_exporter_ai_nonce' ); ?>

        <p style="max-width:720px">
            <?php echo esc_html__( 'Click "Run AI Analysis" to feed the compact AI-ready datasets to the selected provider and download a ZIP of structured analysis files: ai-recommendations.json, ai-internal-link-opportunities.json, ai-cluster-analysis.json, ai-content-gap-signals.json.', 'tse-site-exporter' ); ?>
        </p>
        <p>
            <button type="submit"
                    class="button button-primary"
                    data-testid="tse-ai-run-analysis-button">
                <?php echo esc_html__( 'Run AI Analysis', 'tse-site-exporter' ); ?>
            </button>
        </p>
    </form>
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
    $started_at = gmdate( 'c' );

    try {
        $bundle = tse_exporter_run( $opts );
    } catch ( \Throwable $e ) {
        tse_dashboard_record_run( array(
            'type'        => 'export',
            'status'      => 'failure',
            'message'     => $e->getMessage(),
            'started_at'  => $started_at,
            'finished_at' => gmdate( 'c' ),
            'export_type' => $opts['mode'],
        ) );
        wp_die( esc_html__( 'Export failed: ', 'tse-site-exporter' ) . esc_html( $e->getMessage() ) );
    }

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
        tse_dashboard_record_run( array(
            'type'        => 'export',
            'status'      => 'failure',
            'message'     => 'Could not create ZIP archive.',
            'started_at'  => $started_at,
            'finished_at' => gmdate( 'c' ),
            'export_type' => $opts['mode'],
        ) );
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

    // Persist run in history. ZIP stays on disk so the dashboard can re-serve it.
    tse_dashboard_record_run( array(
        'type'        => 'export',
        'status'      => 'success',
        'started_at'  => $started_at,
        'finished_at' => gmdate( 'c' ),
        'export_type' => $opts['mode'],
        'zip_path'    => $zip_path,
        'zip_name'    => $base_name . '.zip',
        'size'        => file_exists( $zip_path ) ? filesize( $zip_path ) : 0,
        'files'       => array_keys( $bundle ),
    ) );

    while ( ob_get_level() ) {
        ob_end_clean();
    }

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $base_name . '.zip"' );
    header( 'Content-Length: ' . filesize( $zip_path ) );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $zip_path );
    exit;
}
add_action( 'admin_post_tse_site_exporter_export', 'tse_site_exporter_handle_export' );

/**
 * AI: save settings handler.
 */
function tse_site_exporter_handle_ai_save() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }
    check_admin_referer( TSE_SITE_EXPORTER_AI_NONCE, 'tse_site_exporter_ai_nonce' );

    tse_ai_save_settings( $_POST );

    wp_safe_redirect( add_query_arg( array(
        'page'         => 'tse-site-exporter',
        'tse_ai_saved' => '1',
    ), admin_url( 'tools.php' ) ) );
    exit;
}
add_action( 'admin_post_tse_site_exporter_ai_save', 'tse_site_exporter_handle_ai_save' );

/**
 * AI: run analysis handler. Re-runs the export pipeline to derive the
 * compact AI-ready datasets, calls the configured provider, then streams
 * a ZIP of structured analysis JSON files.
 */
function tse_site_exporter_handle_ai_run() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }
    check_admin_referer( TSE_SITE_EXPORTER_AI_NONCE, 'tse_site_exporter_ai_nonce' );

    if ( ! class_exists( 'ZipArchive' ) ) {
        wp_die( esc_html__( 'PHP ZipArchive extension is not available.', 'tse-site-exporter' ) );
    }

    $settings = tse_ai_get_settings();
    $providers_labels = tse_ai_supported_providers();
    $provider = tse_ai_get_provider( $settings['provider'] );
    if ( is_wp_error( $provider ) ) {
        tse_dashboard_record_run( array(
            'type'        => 'ai',
            'status'      => 'failure',
            'message'     => $provider->get_error_message(),
            'started_at'  => gmdate( 'c' ),
            'finished_at' => gmdate( 'c' ),
            'provider'    => $settings['provider'],
            'provider_label' => isset( $providers_labels[ $settings['provider'] ] ) ? $providers_labels[ $settings['provider'] ] : $settings['provider'],
        ) );
        wp_die( esc_html( $provider->get_error_message() ) );
    }
    $key_check = $provider->get_key();
    if ( is_wp_error( $key_check ) ) {
        tse_dashboard_record_run( array(
            'type'        => 'ai',
            'status'      => 'failure',
            'message'     => $key_check->get_error_message(),
            'started_at'  => gmdate( 'c' ),
            'finished_at' => gmdate( 'c' ),
            'provider'    => $provider->slug(),
            'provider_label' => isset( $providers_labels[ $provider->slug() ] ) ? $providers_labels[ $provider->slug() ] : $provider->slug(),
            'model'       => $provider->get_model(),
        ) );
        wp_die( esc_html( $key_check->get_error_message() ) );
    }

    $started_at = gmdate( 'c' );

    @set_time_limit( 0 );
    @ini_set( 'memory_limit', '512M' );

    // Re-build the AI-ready datasets via the standard export pipeline.
    $bundle = tse_exporter_run( array(
        'mode'           => 'quick',
        'live_fetch'     => true,
        'broken_check'   => false,
        'include_slices' => true,
        'quick_cap'      => 500,
    ) );

    $required = array( 'ai-site-summary.json', 'ai-page-summaries.json', 'ai-linking-summary.json', 'ai-cluster-summary.json' );
    foreach ( $required as $f ) {
        if ( ! isset( $bundle[ $f ] ) ) {
            tse_dashboard_record_run( array(
                'type'           => 'ai',
                'status'         => 'failure',
                'message'        => sprintf( 'AI Analysis cannot run: %s missing from export bundle.', $f ),
                'started_at'     => $started_at,
                'finished_at'    => gmdate( 'c' ),
                'provider'       => $provider->slug(),
                'provider_label' => isset( $providers_labels[ $provider->slug() ] ) ? $providers_labels[ $provider->slug() ] : $provider->slug(),
                'model'          => $provider->get_model(),
            ) );
            wp_die( esc_html( sprintf( 'AI Analysis cannot run: %s missing from export bundle.', $f ) ) );
        }
    }

    $inputs = array(
        'site'     => $bundle['ai-site-summary.json'],
        'pages'    => $bundle['ai-page-summaries.json'],
        'linking'  => $bundle['ai-linking-summary.json'],
        'cluster'  => $bundle['ai-cluster-summary.json'],
        'strategy' => array(
            'config'   => isset( $bundle['strategy-config.json'] )   ? $bundle['strategy-config.json']   : null,
            'mismatch' => isset( $bundle['strategy-mismatch.json'] ) ? $bundle['strategy-mismatch.json'] : null,
            'buckets'  => isset( $bundle['strategy-config.json']['buckets'] ) ? $bundle['strategy-config.json']['buckets'] : array(),
            'items'    => isset( $bundle['strategy-mismatch.json']['items'] ) ? $bundle['strategy-mismatch.json']['items'] : array(),
        ),
    );

    $files = tse_ai_runner_execute( $provider, $inputs );

    // V2.5.1+: also render static HTML reports into the same ZIP. Pass the
    // AI-summary inputs as context so reports can resolve URL → title,
    // page-type pills, executive summary and quick-wins blocks.
    $reports = tse_ai_report_build( $files, $inputs );
    foreach ( $reports as $name => $html ) {
        $files[ $name ] = $html;
    }
    if ( isset( $files['manifest.json']['files'] ) ) {
        $files['manifest.json']['files'] = array_keys( $files );
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'tse-site-exporter';
    if ( ! file_exists( $tmp_dir ) ) {
        wp_mkdir_p( $tmp_dir );
    }

    $timestamp = gmdate( 'Ymd-His' );
    $site_slug = sanitize_title( get_bloginfo( 'name' ) );
    if ( empty( $site_slug ) ) $site_slug = 'site';
    $base_name = 'tse-ai-analysis-' . $site_slug . '-' . $timestamp;
    $zip_path  = trailingslashit( $tmp_dir ) . $base_name . '.zip';

    $zip = new ZipArchive();
    if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
        tse_dashboard_record_run( array(
            'type'           => 'ai',
            'status'         => 'failure',
            'message'        => 'Could not create ZIP archive.',
            'started_at'     => $started_at,
            'finished_at'    => gmdate( 'c' ),
            'provider'       => $provider->slug(),
            'provider_label' => isset( $providers_labels[ $provider->slug() ] ) ? $providers_labels[ $provider->slug() ] : $provider->slug(),
            'model'          => $provider->get_model(),
        ) );
        wp_die( esc_html__( 'Could not create ZIP archive.', 'tse-site-exporter' ) );
    }
    foreach ( $files as $filename => $payload ) {
        if ( is_string( $payload ) ) {
            // HTML reports (or any pre-rendered string) ship verbatim.
            $zip->addFromString( $filename, $payload );
            continue;
        }
        $json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        if ( false === $json ) continue;
        $zip->addFromString( $filename, $json );
    }
    $zip->close();

    tse_dashboard_record_run( array(
        'type'           => 'ai',
        'status'         => 'success',
        'started_at'     => $started_at,
        'finished_at'    => gmdate( 'c' ),
        'provider'       => $provider->slug(),
        'provider_label' => isset( $providers_labels[ $provider->slug() ] ) ? $providers_labels[ $provider->slug() ] : $provider->slug(),
        'model'          => $provider->get_model(),
        'zip_path'       => $zip_path,
        'zip_name'       => $base_name . '.zip',
        'size'           => file_exists( $zip_path ) ? filesize( $zip_path ) : 0,
        'files'          => array_keys( $files ),
    ) );

    while ( ob_get_level() ) { ob_end_clean(); }

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . $base_name . '.zip"' );
    header( 'Content-Length: ' . filesize( $zip_path ) );
    header( 'X-Content-Type-Options: nosniff' );
    readfile( $zip_path );
    exit;
}
add_action( 'admin_post_tse_site_exporter_ai_run', 'tse_site_exporter_handle_ai_run' );
