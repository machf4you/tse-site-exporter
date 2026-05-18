<?php
/**
 * Smoke test: TSE Site Exporter — Strategic SEO Configuration Layer (V2.9.0).
 *
 * Validates the pure helpers WITHOUT loading WordPress:
 *   - tse_strategy_normalise_url canonicalises URLs and paths consistently
 *   - tse_strategy_parse_textarea trims, dedupes, drops comments
 *   - tse_strategy_save / get roundtrip
 *   - tse_strategy_build_index returns bucket → URL membership
 *   - tse_strategy_build_mismatch emits the 7 expected finding types
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

// --- WP shims ---------------------------------------------------------------
$GLOBALS['__opts'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . ltrim( $p, '/' ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_nonce_field( ...$a ) {}
function check_admin_referer( ...$a ) {}
function current_user_can( $c ) { return true; }
function wp_safe_redirect( $u ) {}
function wp_die( ...$a ) { throw new RuntimeException( 'wp_die: ' . ( is_string( $a[0] ?? null ) ? $a[0] : '' ) ); }
function add_action( ...$a ) {}
function esc_html( $s )      { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s )      { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )       { return $s; }
function esc_textarea( $s )  { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }
function wp_parse_url( $u ) { return parse_url( $u ); }

require_once __DIR__ . '/tse-site-exporter/includes/strategy.php';

// --- Assertions -------------------------------------------------------------
$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== TSE Site Exporter — strategy layer smoke test ===\n";

// 1. URL normalisation
check( 'normalise full URL',          tse_strategy_normalise_url( 'https://example.test/Foo/Bar' )    === '/foo/bar/' );
check( 'normalise path',              tse_strategy_normalise_url( '/foo/bar' )                       === '/foo/bar/' );
check( 'normalise bare relative',     tse_strategy_normalise_url( 'foo/bar/' )                       === '/foo/bar/' );
check( 'normalise trailing slash',    tse_strategy_normalise_url( '/foo/' )                          === '/foo/' );
check( 'normalise strips query',      tse_strategy_normalise_url( '/foo/?x=1' )                      === '/foo/' );
check( 'normalise strips fragment',   tse_strategy_normalise_url( '/foo/#section' )                  === '/foo/' );
check( 'normalise root',              tse_strategy_normalise_url( 'https://example.test/' )          === '/' );
check( 'normalise empty -> empty',    tse_strategy_normalise_url( '' )                               === '' );

// 2. Textarea parsing
$raw = "https://example.test/foo/\n/foo/\n  # this is a comment\n\n/bar/\nfoo/baz/\n/Bar/  \n/bar/?utm=1";
$parsed = tse_strategy_parse_textarea( $raw );
check( 'parser drops duplicates after normalisation', count( $parsed ) === 3, 'got ' . count( $parsed ) );
check( 'parser drops comment lines',  ! in_array( '# this is a comment', $parsed, true ) );

// 3. Save + get roundtrip
tse_strategy_save( [
    'active_strategic_targets' => "/bathroom-renovations/\n/kitchen-fitting/",
    'support_pages'            => "/how-long-does-a-bathroom-renovation-take/",
    'geo_location_targets'     => "/bathroom-renovations-leeds/",
    'priority_urls'            => "/our-process/",
    'primary_conversion_pages' => "/get-a-quote/",
    'protected_urls'           => "/legacy-campaign/",
] );
$saved = tse_strategy_get();
check( 'roundtrip strategic targets 2 entries', count( $saved['active_strategic_targets'] ) === 2 );
check( 'roundtrip support 1 entry',             count( $saved['support_pages'] )            === 1 );
check( 'roundtrip protected 1 entry',           count( $saved['protected_urls'] )           === 1 );

// 4. Build index
$idx = tse_strategy_build_index( $saved );
check( 'index has /bathroom-renovations/ → active_strategic_targets', in_array( 'active_strategic_targets', $idx['/bathroom-renovations/'] ?? [], true ) );
check( 'index has /get-a-quote/ → primary',                            in_array( 'primary_conversion_pages',     $idx['/get-a-quote/'] ?? [], true ) );

// 5. Mismatch detection with a contrived fixture
$records = [
    // Money page, well-linked (no mismatch expected)
    [
        'url' => 'https://site.test/kitchen-fitting/',
        'title' => 'Kitchen Fitting',
        'relationships' => [ 'incoming_link_count' => 8, 'inbound_classifications' => [ 'support' => 5 ] ],
        'authority'     => [ 'strategic_type' => 'money', 'internal_authority_score' => 80 ],
    ],
    // Money page, under-linked → expect HIGH "under-linked"
    [
        'url' => 'https://site.test/bathroom-renovations/',
        'title' => 'Bathroom Renovations',
        'relationships' => [ 'incoming_link_count' => 2, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'money', 'internal_authority_score' => 15 ],
    ],
    // Support page that classifies as money → role conflict
    [
        'url' => 'https://site.test/how-long-does-a-bathroom-renovation-take/',
        'title' => 'How long does it take?',
        'relationships' => [ 'incoming_link_count' => 6, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'money', 'internal_authority_score' => 70 ],
    ],
    // Location page heuristic says "service"
    [
        'url' => 'https://site.test/bathroom-renovations-leeds/',
        'title' => 'Bathroom Renovations Leeds',
        'relationships' => [ 'incoming_link_count' => 5, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'service', 'internal_authority_score' => 60 ],
    ],
    // Priority URL with only 2 inbound
    [
        'url' => 'https://site.test/our-process/',
        'title' => 'Our Process',
        'relationships' => [ 'incoming_link_count' => 2, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'support', 'internal_authority_score' => 40 ],
    ],
    // Primary conversion with NO money inbound → high
    [
        'url' => 'https://site.test/get-a-quote/',
        'title' => 'Get a quote',
        'relationships' => [ 'incoming_link_count' => 4, 'inbound_classifications' => [ 'article' => 3, 'support' => 1 ] ],
        'authority'     => [ 'strategic_type' => 'other', 'internal_authority_score' => 35 ],
    ],
    // Protected URL involved in duplicate meta title set
    [
        'url' => 'https://site.test/legacy-campaign/',
        'title' => 'Legacy',
        'relationships' => [ 'incoming_link_count' => 6, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'other', 'internal_authority_score' => 55 ],
    ],
    [
        'url' => 'https://site.test/other-page-with-same-title/',
        'title' => 'Other',
        'relationships' => [ 'incoming_link_count' => 3, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'article', 'internal_authority_score' => 30 ],
    ],
];

$extras = [
    'duplicate_meta_titles' => [
        [ 'title' => 'Same Title', 'urls' => [ 'https://site.test/legacy-campaign/', 'https://site.test/other-page-with-same-title/' ] ],
    ],
];

$mismatch = tse_strategy_build_mismatch( $saved, $records, [], [], $extras );
$items    = $mismatch['items'];

// Helper for filtering
function find_by( $items, $field, $val ) {
    return array_values( array_filter( $items, fn($i) => isset( $i[ $field ] ) && $i[ $field ] === $val ) );
}

// Expected findings (V2.10 strings)
$under_linked  = array_values( array_filter( $items, fn($i) => $i['issue'] === 'Declared strategic target is under-linked' ) );
$below_median  = array_values( array_filter( $items, fn($i) => $i['issue'] === 'Declared strategic target sits below the site-wide median support level' ) );
$priority_weak = array_values( array_filter( $items, fn($i) => $i['issue'] === 'Declared priority URL has limited internal support' ) );
$pc_no_inbound = array_values( array_filter( $items, fn($i) => $i['issue'] === 'Primary conversion page is not linked to from any strategic target' ) );
$support_role  = array_values( array_filter( $items, fn($i) => $i['issue'] === 'Declared support page is behaving like a strategic target' ) );
$loc_role      = array_values( array_filter( $items, fn($i) => $i['issue'] === 'Declared location page is not detected as a location by URL / schema signals' ) );
$protected_dup = array_values( array_filter( $items, fn($i) => $i['issue'] === 'Protected URL shares its meta title with another page' ) );

check( 'finds under-linked strategic target',     count( $under_linked )  === 1 );
check( 'finds below-median strategic target',     count( $below_median )  >= 1 );
check( 'finds priority URL with limited links',   count( $priority_weak ) === 1 );
check( 'finds primary-conversion w/o inbound',    count( $pc_no_inbound ) === 1 );
check( 'finds support→strategic role conflict',   count( $support_role )  === 1 );
check( 'finds location role conflict',            count( $loc_role )      === 1 );
check( 'finds protected URL meta dup',            count( $protected_dup ) === 1 );

// Negatives — well-supported strategic target should NOT trigger under-linked.
check( 'well-linked strategic target does NOT trigger under-linked',
    ! in_array( 'https://site.test/kitchen-fitting/', array_merge( ...array_column( $under_linked, 'affected_pages' ) ), true )
);

// Recommendation wording is plain English (no banned words)
$banned = [ 'PageRank', 'link equity', 'passes strong', 'topical authority signals', 'crawl prominence', 'internal equity' ];
$any_banned = false;
foreach ( $items as $it ) {
    foreach ( $banned as $b ) if ( false !== stripos( $it['recommendation'], $b ) ) { $any_banned = true; break 2; }
}
check( 'no banned jargon in deterministic recommendations', ! $any_banned );

// Totals exposed
check( 'mismatch.totals.declared_total = 7',     $mismatch['totals']['declared_total']    === 7 );
check( 'mismatch.totals.declared_resolved = 7',  $mismatch['totals']['declared_resolved'] === 7 );
check( 'mismatch.totals.mismatch_findings >= 7', $mismatch['totals']['mismatch_findings'] >= 7 );

// 6. Unresolved declared URLs are reported.
tse_strategy_save( [ 'active_strategic_targets' => "/does-not-exist/" ] );
$mismatch_empty = tse_strategy_build_mismatch( tse_strategy_get(), $records, [], [], [] );
check( 'unresolved declared URL is surfaced',
    in_array( '/does-not-exist/', $mismatch_empty['unresolved_declared_urls'], true )
);

echo "\n";
if ( $fail === 0 ) { echo "ALL ASSERTIONS PASS\n"; exit(0); }
echo "FAILED: $fail assertion(s)\n"; exit(1);
