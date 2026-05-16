<?php
/**
 * Smoke test: TSE Site Exporter — V2.9 AI report renderer.
 *
 * Validates that:
 *   - Internal-link-report.html now uses the FROM/TO/Anchor/Reason card layout
 *     (no <table>; one .link-card per item)
 *   - The main ai-report.html includes the new "Strategy vs reality" section
 *     when strategy.buckets has declared URLs
 *   - The strategy section is OMITTED when no buckets are declared
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

// --- WP shims ---------------------------------------------------------------
function home_url() { return 'https://site.test'; }
function get_bloginfo( $k = '' ) { return 'Test Site'; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )  { return $s; }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }

require_once __DIR__ . '/tse-site-exporter/includes/ai_report.php';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== TSE Site Exporter — V2.9 report renderer smoke test ===\n";

$meta = [ 'provider' => 'openai', 'model' => 'gpt-5.2', 'site_url' => 'https://site.test', 'site_name' => 'Test', 'generated_at' => '2026-05-16T08:00:00Z' ];

$context_pages = [
    [ 'url' => 'https://site.test/blog/bathroom-tips/', 'title' => 'Bathroom Tips', 'strategic_type' => 'article' ],
    [ 'url' => 'https://site.test/bathroom-renovations/', 'title' => 'Bathroom Renovations', 'strategic_type' => 'money' ],
];
$page_index = tse_ai_report_build_page_index( [ 'pages' => $context_pages ] );

// 1. Internal-link card output
$links_fixture = [
    'status' => 'ok',
    'items'  => [
        [
            'priority'         => 'high',
            'issue'            => 'Money page lacks support',
            'source_url'       => 'https://site.test/blog/bathroom-tips/',
            'target_url'       => 'https://site.test/bathroom-renovations/',
            'suggested_anchor' => 'bathroom renovation services',
            'reason'           => 'Readers of the tips article are the exact audience considering booking a renovation.',
            'confidence_score' => 0.92,
            'recommendation'   => 'Add an internal link from /blog/bathroom-tips/ to /bathroom-renovations/ using anchor "bathroom renovation services".',
            'affected_pages'   => [ 'https://site.test/blog/bathroom-tips/', 'https://site.test/bathroom-renovations/' ],
        ],
    ],
];
$html = tse_ai_report_links( $meta, $links_fixture, $page_index );

check( 'card layout present (.link-card)',     false !== strpos( $html, 'link-card' ) );
check( 'shows "Add internal link" title',      false !== strpos( $html, 'Add internal link' ) );
check( 'shows From label',                     false !== strpos( $html, '>From<' ) );
check( 'shows To label',                       false !== strpos( $html, '>To<' ) );
check( 'shows Suggested anchor label',         false !== strpos( $html, '>Suggested anchor<' ) );
check( 'shows Reason label',                   false !== strpos( $html, '>Reason<' ) );
check( 'shows quoted anchor text',             false !== strpos( $html, '&quot;bathroom renovation services&quot;' ) );
check( 'old table layout removed',             false === strpos( $html, '<th>Source → Target</th>' ) );
check( 'shows source path',                    false !== strpos( $html, '/blog/bathroom-tips/' ) );
check( 'shows target path',                    false !== strpos( $html, '/bathroom-renovations/' ) );

// 2. Main report — strategy block appears when declared
$recs_fixture = [ 'status' => 'ok', 'items' => [] ];
$gaps_fixture = [ 'status' => 'ok', 'items' => [] ];
$context_with_strategy = [
    'pages'    => $context_pages,
    'linking'  => [],
    'strategy' => [
        'buckets'  => [ 'money_pages' => [ '/bathroom-renovations/' ] ],
        'mismatch' => [
            'totals' => [ 'declared_total' => 1, 'declared_resolved' => 1, 'declared_unresolved' => 0, 'mismatch_findings' => 1 ],
            'items'  => [
                [
                    'priority'         => 'high',
                    'issue'            => 'Declared money page is under-linked',
                    'affected_pages'   => [ 'https://site.test/bathroom-renovations/' ],
                    'recommendation'   => 'Add at least 3 contextual internal links pointing to /bathroom-renovations/.',
                    'confidence_score' => 1.0,
                    'category'         => 'strategy',
                ],
            ],
        ],
    ],
];

$main_html = tse_ai_report_main( $meta, $recs_fixture, $gaps_fixture, $links_fixture, $context_with_strategy, $page_index );

check( 'main report includes Strategy vs reality heading',
    false !== strpos( $main_html, 'Strategy vs reality' ) );
check( 'main report mentions declared mismatch issue',
    false !== strpos( $main_html, 'Declared money page is under-linked' ) );
check( 'main report shows declaration counts',
    false !== strpos( $main_html, '1</strong> URL' ) );

// 3. Strategy block is suppressed when no buckets declared.
$context_no_strategy = [
    'pages'    => $context_pages,
    'linking'  => [],
    'strategy' => [ 'buckets' => [], 'mismatch' => null ],
];
$main_no = tse_ai_report_main( $meta, $recs_fixture, $gaps_fixture, $links_fixture, $context_no_strategy, $page_index );
check( 'no strategy declared → no Strategy vs reality section',
    false === strpos( $main_no, 'Strategy vs reality' ) );

echo "\n";
if ( $fail === 0 ) { echo "ALL ASSERTIONS PASS\n"; exit(0); }
echo "FAILED: $fail assertion(s)\n"; exit(1);
