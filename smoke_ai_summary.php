<?php
/**
 * Smoke test: V2.4.0 AI Analysis Layer.
 *
 * Reuses the V2.3 fixture pattern, runs through postprocess + relationships +
 * authority + ai_summary, and validates the AI-ready datasets.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'TSE_SITE_EXPORTER_VERSION', '2.4.0-test' );

function home_url()       { return 'https://example.com'; }
function get_bloginfo($k) { return $k === 'name' ? 'Example' : '6.5'; }
function wp_json_encode($d, $f = 0) { return json_encode( $d, $f ); }
function wp_parse_url($u) { return parse_url( $u ); }
function wp_strip_all_tags($s) { return strip_tags( (string) $s ); }
function get_option($k) { return 0; }

require_once __DIR__ . '/tse-site-exporter/includes/postprocess.php';
require_once __DIR__ . '/tse-site-exporter/includes/schema.php';
require_once __DIR__ . '/tse-site-exporter/includes/relationships.php';
require_once __DIR__ . '/tse-site-exporter/includes/authority.php';
require_once __DIR__ . '/tse-site-exporter/includes/ai_summary.php';
require_once __DIR__ . '/tse-site-exporter/includes/exporter.php';

function mk( $id, $url, $type, $classification, $internal_targets, $extra = array() ) {
    $li = array();
    foreach ( $internal_targets as $t ) {
        $li[] = array(
            'url' => $t['url'], 'anchor' => $t['anchor'],
            'rel' => isset( $t['rel'] ) ? $t['rel'] : array(), 'is_self' => false,
            'source_post_type' => $type, 'source_classification' => $classification,
            'target_post_type' => 'page', 'target_classification' => 'unknown', 'target_id' => 0,
        );
    }
    return array_merge( array(
        'id' => $id, 'url' => $url, 'post_type' => $type, 'classification' => $classification, 'parent_id' => 0,
        'content' => array( 'h1' => '', 'h2' => array(), 'h3' => array(), 'faqs' => array(), 'word_count' => 0, 'plain_text' => '' ),
        'seo' => array( 'title' => '', 'description' => '', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
        'links' => array( 'internal' => $li, 'external' => array() ),
        'schema' => array(), 'cro' => array(), 'elementor' => array( 'is_elementor' => false ),
    ), $extra );
}

$records = array(
    mk( 1, 'https://example.com/', 'page', 'homepage', array(
        array( 'url' => 'https://example.com/services/seo/',        'anchor' => 'Professional SEO services' ),
        array( 'url' => 'https://example.com/services/web-design/', 'anchor' => 'Custom web design' ),
        array( 'url' => 'https://example.com/locations/london/',    'anchor' => 'Our London office' ),
        array( 'url' => 'https://example.com/blog/welcome/',        'anchor' => 'Read more' ),
        array( 'url' => 'https://example.com/contact/',             'anchor' => 'Contact us' ),
    ), array(
        'content' => array( 'h1' => 'Welcome', 'h2' => array( 'Our services', 'About us' ), 'h3' => array(), 'faqs' => array(), 'word_count' => 800, 'plain_text' => str_repeat( 'home ', 800 ) ),
        'seo' => array( 'title' => 'Example - Home', 'description' => 'Example official homepage with services and offices around the UK and beyond.', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
    ) ),
    mk( 2, 'https://example.com/services/seo/', 'page', 'money', array(
        array( 'url' => 'https://example.com/contact/', 'anchor' => 'Get a free SEO quote' ),
    ), array(
        'content' => array( 'h1' => 'SEO services that scale revenue', 'h2' => array( 'Why us' ), 'h3' => array(), 'faqs' => array(), 'word_count' => 1200, 'plain_text' => '' ),
        'seo' => array( 'title' => 'SEO Services', 'description' => 'Drive qualified organic traffic with measurable SEO services for B2B and B2C teams.', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
        'cro' => array( 'cta_count' => 3, 'form_count' => 1, 'phone_count' => 1 ),
    ) ),
    // Under-supported money page: same metadata as another -> duplicate.
    mk( 3, 'https://example.com/services/web-design/', 'page', 'money', array(
        array( 'url' => 'https://example.com/contact/', 'anchor' => 'Start your project' ),
    ), array(
        'content' => array( 'h1' => 'Custom web design', 'h2' => array( 'Process' ), 'h3' => array(), 'faqs' => array(), 'word_count' => 100, 'plain_text' => '' ),
        'seo' => array( 'title' => 'Web Design', 'description' => '', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
    ) ),
    mk( 4, 'https://example.com/locations/london/', 'page', 'money', array(), array(
        'content' => array( 'h1' => '', 'h2' => array(), 'h3' => array(), 'faqs' => array(), 'word_count' => 80, 'plain_text' => '' ),
        'seo' => array( 'title' => '', 'description' => '', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
    ) ),
    mk( 5, 'https://example.com/blog/welcome/', 'post', 'article', array(
        array( 'url' => 'https://example.com/services/seo/', 'anchor' => 'our SEO services' ),
    ), array(
        'content' => array( 'h1' => 'Welcome post', 'h2' => array( 'Intro' ), 'h3' => array(), 'faqs' => array(), 'word_count' => 600, 'plain_text' => '' ),
        'seo' => array( 'title' => 'Welcome to the Example blog', 'description' => 'A quick intro to what our blog will cover for our customers and prospects.', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
    ) ),
    mk( 6, 'https://example.com/contact/', 'page', 'money', array(), array(
        'content' => array( 'h1' => 'Contact us', 'h2' => array(), 'h3' => array(), 'faqs' => array(), 'word_count' => 200, 'plain_text' => '' ),
        'seo' => array( 'title' => 'Contact - Example', 'description' => 'Get in touch with the Example team via phone, email or our contact form.', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
    ) ),
    // Isolated orphan: no inbound, no outbound.
    mk( 7, 'https://example.com/help/faq/', 'page', 'support', array(), array(
        'content' => array( 'h1' => 'FAQ', 'h2' => array(), 'h3' => array(), 'faqs' => array( array( 'q' => 'q', 'a' => 'a' ) ), 'word_count' => 250, 'plain_text' => '' ),
        'seo' => array( 'title' => 'FAQ', 'description' => '', 'focus_keywords' => array(), 'canonical' => '', 'robots' => array(), 'og' => array(), 'source' => 'core' ),
    ) ),
);

$url_index = array();
foreach ( $records as $i => $r ) $url_index[ tse_normalize_url( $r['url'] ) ] = $i;

$opts = array( 'mode' => 'quick', 'live_fetch' => false, 'broken_check' => false, 'include_slices' => true, 'quick_cap' => 500 );

$postprocess   = tse_postprocess_build( $records, $url_index, $opts );
$relationships = tse_relationships_build( $records, $url_index );
$authority     = tse_authority_build( $records, $url_index, $relationships );
foreach ( $records as &$r ) {
    $n = tse_normalize_url( $r['url'] );
    $r['relationships'] = $relationships['per_page'][ $n ];
    $r['authority']     = $authority['per_page'][ $n ];
}
unset( $r );

$bundle = tse_exporter_assemble_bundle( $records, $postprocess, $relationships, $authority, $opts, false, array( 'page', 'post' ) );
$ai     = tse_ai_summary_build( $records, $relationships, $authority, $postprocess );
foreach ( $ai['files'] as $n => $p ) $bundle[ $n ] = $p;
$bundle['manifest.json']['files'] = array_keys( $bundle );

$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== V2.4.0 AI Analysis Layer smoke test ===\n";

// 1. AI files present
foreach ( array( 'ai-site-summary.json', 'ai-page-summaries.json', 'ai-linking-summary.json', 'ai-cluster-summary.json' ) as $f ) {
    check( "bundle has $f", isset( $bundle[ $f ] ) );
}
check( 'manifest.files updated with AI files', in_array( 'ai-site-summary.json', $bundle['manifest.json']['files'], true ) );

// 2. ai-page-summaries: one per record, no raw plain_text leak
$pages = $bundle['ai-page-summaries.json'];
check( 'page summary count matches records', count( $pages ) === count( $records ), 'got ' . count( $pages ) );

$by_id = array();
foreach ( $pages as $p ) $by_id[ $p['id'] ] = $p;

check( 'page has no plain_text field', ! isset( $pages[0]['plain_text'] ) );
check( 'page has no elementor field', ! isset( $pages[0]['elementor'] ) );
check( 'page has no schema field', ! isset( $pages[0]['schema'] ) );

// 3. Issue detection
check( 'london (id=4) flagged missing_meta_title', in_array( 'missing_meta_title', $by_id[4]['issues'], true ) );
check( 'london (id=4) flagged weak_h1', in_array( 'weak_h1', $by_id[4]['issues'], true ) );
check( 'london (id=4) flagged thin_content', in_array( 'thin_content', $by_id[4]['issues'], true ) );
check( 'london (id=4) flagged near_orphan (has 1 incoming from homepage)', in_array( 'near_orphan', $by_id[4]['issues'], true ), implode( ',', $by_id[4]['issues'] ) );
check( 'web-design (id=3) flagged missing_meta_description', in_array( 'missing_meta_description', $by_id[3]['issues'], true ) );
check( 'web-design (id=3) flagged thin_content', in_array( 'thin_content', $by_id[3]['issues'], true ) );
check( 'faq (id=7) flagged no_incoming_links AND no_outgoing_internal_links',
    in_array( 'no_incoming_links', $by_id[7]['issues'], true )
    && in_array( 'no_outgoing_internal_links', $by_id[7]['issues'], true ) );
check( 'homepage (id=1) NOT flagged no_incoming_links', ! in_array( 'no_incoming_links', $by_id[1]['issues'], true ) );

// 4. Cluster id present, isolated flag works
check( 'page has cluster_id', isset( $by_id[1]['cluster_id'] ) && $by_id[1]['cluster_id'] !== null );
check( 'faq (id=7) marked isolated', $by_id[7]['is_isolated'] === true );

// 5. Site summary basics
$site = $bundle['ai-site-summary.json'];
check( 'site totals.pages matches', $site['totals']['pages'] === count( $records ) );
check( 'distribution.by_strategic_type has money', isset( $site['distribution']['by_strategic_type']['money'] ) );
check( 'top_authorities non-empty', count( $site['top_authorities'] ) > 0 );
check( 'issue_counts populated', ! empty( $site['issue_counts'] ) );

// 6. Linking summary
$link = $bundle['ai-linking-summary.json'];
check( 'orphan_pages contains FAQ', (bool) array_filter( $link['orphan_pages'], function( $p ) { return $p['url'] === 'https://example.com/help/faq/'; } ) );
check( 'orphan_pages contains FAQ only (London is near-orphan, not orphan)',
    1 === count( $link['orphan_pages'] )
    && $link['orphan_pages'][0]['url'] === 'https://example.com/help/faq/' );
check( 'near_orphan_pages contains London', (bool) array_filter( $link['near_orphan_pages'], function( $p ) { return $p['url'] === 'https://example.com/locations/london/'; } ) );
check( 'weak_money_pages non-empty', count( $link['weak_money_pages'] ) > 0 );
check( 'linking_opportunities non-empty', count( $link['linking_opportunities'] ) > 0 );

// 7. Cluster summary
$cs = $bundle['ai-cluster-summary.json'];
$has_isolated = false; $has_bridge = false;
foreach ( $cs['clusters'] as $c ) {
    if ( $c['is_isolated'] ) {
        $has_isolated = true;
        if ( $c['recommended_bridge'] ) $has_bridge = true;
    }
}
check( 'cluster summary has isolated cluster', $has_isolated );
check( 'isolated cluster has recommended_bridge', $has_bridge );

// 8. Token economy sanity: page summary JSON < 2KB per page on this tiny fixture
$avg_size = strlen( json_encode( $pages ) ) / max( 1, count( $pages ) );
check( "page summary avg size reasonable (< 2KB)", $avg_size < 2048, sprintf( '%.0f bytes', $avg_size ) );

echo "\n";
if ( $fail === 0 ) { echo "ALL ASSERTIONS PASS\n"; exit( 0 ); }
echo "FAILED: $fail assertion(s)\n"; exit( 1 );
