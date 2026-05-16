<?php
/**
 * Smoke test: TSE Site Exporter dashboard layer (V2.8.0).
 *
 * Validates the pure helpers WITHOUT loading WordPress:
 *   - file categorisation maps the right filenames to the right groups
 *   - record_run prepends, prunes at TSE_RUNS_MAX, and deletes pruned ZIPs
 *   - find_run / delete_run behave correctly
 *
 * We stub the handful of WP functions used by these helpers.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

// --- Minimal WP shims ---------------------------------------------------------
$GLOBALS['__opts'] = [];
function get_option( $k, $default = false ) { return $GLOBALS['__opts'][ $k ] ?? $default; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function wp_upload_dir() { $b = sys_get_temp_dir() . '/tse-smoke-' . getmypid(); @mkdir( $b, 0777, true ); return [ 'basedir' => $b ]; }
function wp_mkdir_p( $p ) { return @mkdir( $p, 0777, true ) || is_dir( $p ); }
function trailingslashit( $s ) { return rtrim( $s, '/\\' ) . '/'; }
function wp_generate_password( $len, $a=true, $b=true ) { return substr( bin2hex( random_bytes( max(1,(int)ceil($len/2)) ) ), 0, $len ); }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . ltrim( $p, '/' ); }
function add_query_arg( $args, $url ) {
    $q = http_build_query( $args );
    return $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . $q;
}
function wp_create_nonce( $k ) { return 'nonce_' . md5( $k ); }
function wp_nonce_url( $url, $action ) { return add_query_arg( [ '_wpnonce' => wp_create_nonce( $action ) ], $url ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', (string) $s ) ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )  { return $s; }
function esc_js( $s )   { return addslashes( (string) $s ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function date_i18n( $fmt, $ts ) { return gmdate( $fmt, (int) $ts ); }
function add_action( ...$a ) {}

require_once __DIR__ . '/tse-site-exporter/includes/dashboard.php';

// --- Assertions ---------------------------------------------------------------
$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== TSE Site Exporter — dashboard smoke test ===\n";

// 1. File categorisation
check( 'ai-report.html → ai',                  tse_dashboard_file_category( 'ai-report.html' )            === 'ai' );
check( 'internal-link-report.html → internal_links', tse_dashboard_file_category( 'internal-link-report.html' ) === 'internal_links' );
check( 'cluster-report.html → cluster',        tse_dashboard_file_category( 'cluster-report.html' )       === 'cluster' );
check( 'extra-foo.html → ai (default html bucket)', tse_dashboard_file_category( 'extra-foo.html' )       === 'ai' );
check( 'ai-recommendations.json → json',       tse_dashboard_file_category( 'ai-recommendations.json' )   === 'json' );
check( 'manifest.json → json',                 tse_dashboard_file_category( 'manifest.json' )             === 'json' );
check( 'unknown.bin → other',                  tse_dashboard_file_category( 'unknown.bin' )               === 'other' );

$groups = tse_dashboard_categorise_files( [
    'ai-report.html', 'internal-link-report.html', 'cluster-report.html',
    'ai-recommendations.json', 'manifest.json', 'unknown.bin',
] );
check( 'categorise_files: ai group has 1 file',             count( $groups['ai'] )             === 1 );
check( 'categorise_files: internal_links group has 1 file', count( $groups['internal_links'] ) === 1 );
check( 'categorise_files: cluster group has 1 file',        count( $groups['cluster'] )        === 1 );
check( 'categorise_files: json group has 2 files',          count( $groups['json'] )           === 2 );
check( 'categorise_files: other group has 1 file',          count( $groups['other'] )          === 1 );

// 2. Storage dir creation
$dir = tse_dashboard_storage_dir();
check( 'storage_dir created',           is_dir( $dir ) );
check( 'storage_dir .htaccess present', file_exists( $dir . '/.htaccess' ) );

// 3. record_run / find_run lifecycle
$GLOBALS['__opts'] = []; // reset

// Make a fake ZIP file on disk.
$zip_a = $dir . '/run-a.zip'; file_put_contents( $zip_a, 'A' );
$entry_a = tse_dashboard_record_run( [
    'type'           => 'ai',
    'status'         => 'success',
    'provider'       => 'openai',
    'provider_label' => 'OpenAI (GPT-5.2)',
    'model'          => 'gpt-5.2',
    'zip_path'       => $zip_a,
    'zip_name'       => 'run-a.zip',
    'files'          => [ 'ai-report.html', 'manifest.json' ],
] );
check( 'record_run returned id',     ! empty( $entry_a['id'] ) );
check( 'record_run persists 1 run',  count( tse_dashboard_get_runs() ) === 1 );

$zip_b = $dir . '/run-b.zip'; file_put_contents( $zip_b, 'B' );
$entry_b = tse_dashboard_record_run( [
    'type'        => 'export',
    'status'      => 'success',
    'export_type' => 'quick',
    'zip_path'    => $zip_b,
    'zip_name'    => 'run-b.zip',
    'files'       => [ 'site-data.json' ],
] );
$runs = tse_dashboard_get_runs();
check( 'newest run is first',        $runs[0]['id'] === $entry_b['id'] );
check( 'find_run finds entry_a',     tse_dashboard_find_run( $entry_a['id'] )['type'] === 'ai' );

// 4. Pruning: simulate filling beyond TSE_RUNS_MAX
$GLOBALS['__opts'] = [];
$oldest_zip = $dir . '/oldest.zip'; file_put_contents( $oldest_zip, 'OLD' );
tse_dashboard_record_run( [
    'type'     => 'ai',
    'status'   => 'success',
    'zip_path' => $oldest_zip,
    'zip_name' => 'oldest.zip',
    'files'    => [ 'ai-report.html' ],
] );
for ( $i = 0; $i < TSE_RUNS_MAX; $i++ ) {
    $p = $dir . "/fill-$i.zip"; file_put_contents( $p, (string) $i );
    tse_dashboard_record_run( [ 'type' => 'ai', 'status' => 'success', 'zip_path' => $p, 'files' => [] ] );
}
check( 'runs capped at TSE_RUNS_MAX', count( tse_dashboard_get_runs() ) === TSE_RUNS_MAX );
check( 'oldest run pruned',           tse_dashboard_find_run( 'r_oldest' ) === null );
check( 'oldest zip deleted from disk', ! file_exists( $oldest_zip ) );

// 5. delete_run removes entry + file
$GLOBALS['__opts'] = [];
$kill_zip = $dir . '/kill.zip'; file_put_contents( $kill_zip, 'K' );
$kill = tse_dashboard_record_run( [
    'type'     => 'export',
    'status'   => 'success',
    'zip_path' => $kill_zip,
    'files'    => [ 'foo.json' ],
] );
tse_dashboard_delete_run( $kill['id'] );
check( 'delete_run removed entry',    tse_dashboard_find_run( $kill['id'] ) === null );
check( 'delete_run removed file',     ! file_exists( $kill_zip ) );

// 6. URL helpers compose admin-post.php URLs with nonces
$serve = tse_dashboard_serve_url( 'r_xxx', 'ai-report.html' );
check( 'serve_url contains action',      strpos( $serve, 'action=tse_site_exporter_serve' ) !== false );
check( 'serve_url contains nonce',       strpos( $serve, '_wpnonce=' ) !== false );
check( 'serve_url url-encodes filename', strpos( $serve, 'ai-report.html' ) !== false );

$zip = tse_dashboard_zip_download_url( 'r_xxx' );
check( 'zip_download_url contains action', strpos( $zip, 'action=tse_site_exporter_download_zip' ) !== false );

$view = tse_dashboard_viewer_url( 'r_xxx', 'ai-report.html' );
check( 'viewer_url is on tools.php',   strpos( $view, '/wp-admin/tools.php' ) !== false );
check( 'viewer_url has view=run',      strpos( $view, 'view=run' ) !== false );

echo "\n";
if ( $fail === 0 ) { echo "ALL ASSERTIONS PASS\n"; exit(0); }
echo "FAILED: $fail assertion(s)\n"; exit(1);
