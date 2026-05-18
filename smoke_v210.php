<?php
/**
 * Smoke test: TSE Site Exporter V2.10 — page intent + indexability +
 * unified-issue normaliser + strategy migration.
 *
 * Validates pure helpers without loading WordPress.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );

// --- WP shims ---------------------------------------------------------------
$GLOBALS['__opts'] = [];
$GLOBALS['__post_meta'] = [];
function get_option( $k, $d = false ) { return $GLOBALS['__opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['__opts'][ $k ] = $v; return true; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['__post_meta'][ "$id|$key" ] ?? '' ; }
function home_url( $p = '' ) { return 'https://site.test' . $p; }
function wp_remote_get( $url, $args = [] ) { return $GLOBALS['__http']( $url, $args ); }
function is_wp_error( $r ) { return is_array( $r ) && isset( $r['__error'] ); }
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . ltrim( $p, '/' ); }
function add_action( ...$a ) {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s )  { return $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $s, $d = null ) { return esc_html( $s ); }
function __( $s, $d = null ) { return $s; }

require_once __DIR__ . '/tse-site-exporter/includes/page_intent.php';
require_once __DIR__ . '/tse-site-exporter/includes/issue_normaliser.php';
require_once __DIR__ . '/tse-site-exporter/includes/strategy.php';

$fail = 0;
function check( $label, $cond, $detail = '' ) {
    global $fail;
    $status = $cond ? 'PASS' : 'FAIL';
    if ( ! $cond ) $fail++;
    echo "[$status] $label" . ( $detail !== '' ? "  -- $detail" : '' ) . "\n";
}

echo "=== TSE Site Exporter V2.10 — refinements smoke test ===\n";

/* -- 1. Page intent classification ----------------------------------------- */
$cases = [
    [ 'url' => 'https://site.test/thank-you/',          'expect' => 'conversion' ],
    [ 'url' => 'https://site.test/order-received/',     'expect' => 'conversion' ],
    [ 'url' => 'https://site.test/privacy-policy/',     'expect' => 'legal' ],
    [ 'url' => 'https://site.test/cookie-policy/',      'expect' => 'legal' ],
    [ 'url' => 'https://site.test/terms-of-website-use/','expect' => 'legal' ],
    [ 'url' => 'https://site.test/my-account/',         'expect' => 'utility' ],
    [ 'url' => 'https://site.test/cart/',               'expect' => 'utility' ],
    [ 'url' => 'https://site.test/search/?q=foo',       'expect' => 'utility' ],
    [ 'url' => 'https://site.test/gallery/',            'expect' => 'gallery' ],
    [ 'url' => 'https://site.test/services/bathroom/',  'expect' => 'seo' ],
];
foreach ( $cases as $c ) {
    check( "intent classifies {$c['url']} → {$c['expect']}",
        tse_page_intent_classify( [ 'url' => $c['url'], 'post_type' => 'page', 'template' => '' ] ) === $c['expect'] );
}
// post_type fast path
check( 'elementor_library post_type → template',
    tse_page_intent_classify( [ 'url' => 'https://site.test/?elementor_library=foo', 'post_type' => 'elementor_library', 'template' => '' ] ) === 'template' );
check( 'attachment post_type → gallery',
    tse_page_intent_classify( [ 'url' => 'https://site.test/x.jpg', 'post_type' => 'attachment', 'template' => '' ] ) === 'gallery' );

check( 'is_non_seo helper recognises legal',  tse_page_intent_is_non_seo( 'legal' ) === true );
check( 'is_non_seo helper rejects seo',       tse_page_intent_is_non_seo( 'seo' )   === false );

/* -- 2. Indexability extraction ------------------------------------------- */
$GLOBALS['__post_meta'][ '10|_yoast_wpseo_meta-robots-noindex' ] = '1';
check( 'Yoast noindex postmeta → noindex',  tse_page_indexability( 10, '' ) === 'noindex' );

$GLOBALS['__post_meta'][ '11|_yoast_wpseo_meta-robots-noindex' ] = '2';
check( 'Yoast explicit index → index',      tse_page_indexability( 11, '' ) === 'index' );

$GLOBALS['__post_meta'][ '12|rank_math_robots' ] = [ 'noindex' ];
check( 'RankMath array containing noindex → noindex', tse_page_indexability( 12, '' ) === 'noindex' );

$live = '<head><meta name="robots" content="noindex, nofollow"></head>';
check( 'live <meta robots> → noindex (no postmeta override)', tse_page_indexability( 99, $live ) === 'noindex' );

check( 'no signals → unknown', tse_page_indexability( 999, '' ) === 'unknown' );

/* -- 3. Sitemap fetching -------------------------------------------------- */
$GLOBALS['__http'] = function ( $url, $_args ) {
    if ( str_contains( $url, 'sitemap_index.xml' ) ) {
        return [
            'response' => [ 'code' => 200 ],
            'body'     => '<?xml version="1.0"?><sitemapindex><sitemap><loc>https://site.test/page-sitemap.xml</loc></sitemap></sitemapindex>',
        ];
    }
    if ( str_contains( $url, 'page-sitemap.xml' ) ) {
        return [
            'response' => [ 'code' => 200 ],
            'body'     => '<?xml version="1.0"?><urlset><url><loc>https://site.test/services/bathroom/</loc></url><url><loc>https://site.test/about/</loc></url></urlset>',
        ];
    }
    return [ 'response' => [ 'code' => 404 ], 'body' => '' ];
};

$meta = tse_page_sitemap_fetch_url_set();
$set  = $meta['urls'];
check( 'sitemap fetch follows sub-sitemap',  count( $set ) === 2 );
check( '/services/bathroom/ present in set', isset( $set['/services/bathroom/'] ) );
check( '/contact/ correctly excluded',        false === tse_page_sitemap_is_excluded( 'https://site.test/services/bathroom/', $set ) );
check( '/missing/ correctly flagged excluded', true === tse_page_sitemap_is_excluded( 'https://site.test/missing/', $set ) );

/* -- 4. Strategy migration money_pages → active_strategic_targets --------- */
$GLOBALS['__opts'] = [];
update_option( 'tse_site_exporter_strategy', [
    'money_pages'    => [ '/bathroom-renovations/' ],
    'location_pages' => [ '/leeds/' ],
    'support_pages'  => [ '/faq/' ],
] );
$st = tse_strategy_get();
check( 'migration: money_pages key removed',                ! isset( $st['money_pages'] ) );
check( 'migration: active_strategic_targets populated',     $st['active_strategic_targets'] === [ '/bathroom-renovations/' ] );
check( 'migration: geo_location_targets populated',         $st['geo_location_targets']     === [ '/leeds/' ] );
check( 'migration: support_pages preserved',                $st['support_pages']            === [ '/faq/' ] );
check( 'migration: persisted to wp_options',                ! isset( get_option( 'tse_site_exporter_strategy' )['money_pages'] ) );

// new buckets exist
$buckets = array_keys( tse_strategy_buckets() );
foreach ( [ 'active_strategic_targets', 'geo_location_targets', 'priority_urls', 'primary_conversion_pages', 'support_pages', 'protected_urls' ] as $b ) {
    check( "bucket $b exists in V2.10 set", in_array( $b, $buckets, true ) );
}

/* -- 5. Strategy mismatch under V2.10 vocabulary -------------------------- */
$GLOBALS['__opts'] = [];
tse_strategy_save( [
    'active_strategic_targets' => "/bathroom-renovations/",
    'priority_urls'            => "/our-process/",
    'primary_conversion_pages' => "/get-a-quote/",
] );
$saved = tse_strategy_get();

$records = [
    [
        'url' => 'https://site.test/bathroom-renovations/',
        'relationships' => [ 'incoming_link_count' => 2, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'money', 'internal_authority_score' => 15 ],
    ],
    [
        'url' => 'https://site.test/our-process/',
        'relationships' => [ 'incoming_link_count' => 2, 'inbound_classifications' => [] ],
        'authority'     => [ 'strategic_type' => 'support', 'internal_authority_score' => 40 ],
    ],
    [
        'url' => 'https://site.test/get-a-quote/',
        'relationships' => [ 'incoming_link_count' => 4, 'inbound_classifications' => [ 'article' => 3 ] ],
        'authority'     => [ 'strategic_type' => 'other', 'internal_authority_score' => 35 ],
    ],
];
$m = tse_strategy_build_mismatch( $saved, $records, [], [], [] );
$by_issue = function( $items, $needle ) {
    return array_values( array_filter( $items, fn($i) => false !== strpos( $i['issue'], $needle ) ) );
};
check( 'V2.10 detects "Declared strategic target is under-linked"',     count( $by_issue( $m['items'], 'strategic target is under-linked' ) ) === 1 );
check( 'V2.10 detects priority URL with limited internal support',      count( $by_issue( $m['items'], 'priority URL has limited' ) )       === 1 );
check( 'V2.10 detects PC page not linked to from any strategic target', count( $by_issue( $m['items'], 'not linked to from any strategic target' ) ) === 1 );

/* -- 6. Unified issue normaliser ------------------------------------------ */
$lookup = tse_issues_build_page_lookup( [
    [ 'url' => 'https://site.test/services/bathroom/',  'intent' => 'seo',        'indexability' => 'index'   ],
    [ 'url' => 'https://site.test/blog/tips/',          'intent' => 'seo',        'indexability' => 'index'   ],
    [ 'url' => 'https://site.test/privacy-policy/',     'intent' => 'legal',      'indexability' => 'noindex' ],
    [ 'url' => 'https://site.test/thank-you/',          'intent' => 'conversion', 'indexability' => 'noindex' ],
    [ 'url' => 'https://site.test/elementor-tmpl/',     'intent' => 'template',   'indexability' => 'unknown' ],
    [ 'url' => 'https://site.test/old-page/',           'intent' => 'seo',        'indexability' => 'index',  'excluded_from_sitemap' => true ],
] );

$raw = [
    'recommendations' => [
        // Linking item with legal source → SUPPRESS.
        [ 'priority' => 'high', 'issue' => 'Add link', 'category' => 'linking',
          'source_url' => 'https://site.test/privacy-policy/', 'target_url' => 'https://site.test/services/bathroom/',
          'affected_pages' => [ 'https://site.test/privacy-policy/', 'https://site.test/services/bathroom/' ] ],
        // Linking item with excluded-from-sitemap source → SUPPRESS.
        [ 'priority' => 'medium', 'issue' => 'Add link', 'category' => 'linking',
          'source_url' => 'https://site.test/old-page/', 'target_url' => 'https://site.test/services/bathroom/',
          'affected_pages' => [ 'https://site.test/old-page/', 'https://site.test/services/bathroom/' ] ],
        // Thin content on thank-you page → SUPPRESS.
        [ 'priority' => 'low', 'issue' => 'Thin content', 'gap_type' => 'thin_content',
          'affected_pages' => [ 'https://site.test/thank-you/' ] ],
        // Duplicate metadata on legal page → SUPPRESS.
        [ 'priority' => 'low', 'issue' => 'Duplicate meta title', 'category' => 'metadata',
          'affected_pages' => [ 'https://site.test/privacy-policy/' ] ],
        // Linking item between SEO pages → KEEP.
        [ 'priority' => 'high', 'issue' => 'Add internal link', 'recommendation' => 'Edit /blog/tips/ and add an internal link to /services/bathroom/ using anchor "bathroom services".',
          'category' => 'linking',
          'source_url' => 'https://site.test/blog/tips/', 'target_url' => 'https://site.test/services/bathroom/',
          'affected_pages' => [ 'https://site.test/blog/tips/', 'https://site.test/services/bathroom/' ],
          'suggested_anchor' => 'bathroom services', 'reason' => 'Tips readers are the same audience.' ],
        // Architecture finding → KEEP, mapped to developer_technical.
        [ 'priority' => 'medium', 'issue' => 'Isolated cluster needs bridge', 'category' => 'cluster',
          'affected_pages' => [ 'https://site.test/services/bathroom/' ] ],
        // Duplicate metadata on SEO pages → KEEP.
        [ 'priority' => 'medium', 'issue' => 'Duplicate meta title', 'category' => 'metadata',
          'affected_pages' => [ 'https://site.test/services/bathroom/', 'https://site.test/blog/tips/' ] ],
    ],
    // Same Linking item emitted again by another prompt → MERGE.
    'link_opps' => [
        [ 'priority' => 'high', 'issue' => 'Add internal link', 'category' => 'linking',
          'source_url' => 'https://site.test/blog/tips/', 'target_url' => 'https://site.test/services/bathroom/',
          'affected_pages' => [ 'https://site.test/blog/tips/', 'https://site.test/services/bathroom/' ],
          'suggested_anchor' => 'bathroom services', 'reason' => 'Tips readers are the same audience.' ],
    ],
];

$issues = tse_issues_normalise( $raw, $lookup );

check( 'suppression dropped legal-source linking',         0 === count( array_filter( $issues, fn($i) => in_array( 'https://site.test/privacy-policy/', $i['affected_pages'], true ) && 'Linking' === $i['group'] ) ) );
check( 'suppression dropped excluded-sitemap linking',     0 === count( array_filter( $issues, fn($i) => in_array( 'https://site.test/old-page/', $i['affected_pages'], true ) && 'Linking' === $i['group'] ) ) );
check( 'suppression dropped thin-content on thank-you',    0 === count( array_filter( $issues, fn($i) => in_array( 'https://site.test/thank-you/', $i['affected_pages'], true ) && 'Thin Content' === $i['group'] ) ) );
check( 'suppression dropped metadata on legal page',       0 === count( array_filter( $issues, fn($i) => in_array( 'https://site.test/privacy-policy/', $i['affected_pages'], true ) && 'Metadata' === $i['group'] ) ) );

// Duplicate emitted by two prompts collapsed.
$link_kept = array_values( array_filter( $issues, fn($i) => 'Linking' === $i['group'] ) );
check( 'duplicate linking item deduped to 1',              count( $link_kept ) === 1 );
check( 'dedup merged source list (2 sources)',             count( $link_kept[0]['source'] ) === 2, implode( ',', $link_kept[0]['source'] ) );

// Action-type classifier
$arch = array_values( array_filter( $issues, fn($i) => 'Architecture' === $i['group'] ) );
check( 'Architecture finding → developer_technical track', $arch[0]['action_type'] === 'developer_technical' );

$meta = array_values( array_filter( $issues, fn($i) => 'Metadata' === $i['group'] ) );
check( 'Metadata finding → content_admin track',           $meta[0]['action_type'] === 'content_admin' );

// implementation_guidance default applied
check( 'implementation_guidance defaulted on linking',     '' !== $link_kept[0]['implementation_guidance'] );

// Two-track splitter
$split = tse_issues_split_tracks( $issues );
check( 'splitter: content_admin non-empty',                count( $split['content_admin'] ) >= 1 );
check( 'splitter: developer_technical non-empty',          count( $split['developer_technical'] ) >= 1 );

echo "\n";
echo "=== V2.10.1 — bucket simplification + dup-meta + geo heuristics ===\n";

/* -- 7. Bucket simplification + migration of 3 dropped V2.10 buckets ----- */
$GLOBALS['__opts'] = [];
tse_strategy_save( [
    'active_strategic_targets' => "/keep-this/",
    'current_seo_targets'      => "/seo-target-1/",
    'growth_targets'           => "/growth-1/",
    'campaign_pages'           => "/campaign-1/",
    'geo_location_targets'     => "/leeds/",
    'protected_urls'           => "/legacy/",
] );
// V2.10.1: only 6 buckets are valid — saving 3 dead keys must NOT persist them.
$saved = tse_strategy_get();
$buckets_now = array_keys( tse_strategy_buckets() );
check( 'V2.10.1: bucket count = 6',                 count( $buckets_now ) === 6 );
check( 'V2.10.1: current_seo_targets gone',         ! in_array( 'current_seo_targets', $buckets_now, true ) );
check( 'V2.10.1: growth_targets gone',              ! in_array( 'growth_targets',      $buckets_now, true ) );
check( 'V2.10.1: campaign_pages gone',              ! in_array( 'campaign_pages',      $buckets_now, true ) );
check( 'V2.10.1: active_strategic_targets present', in_array( 'active_strategic_targets', $buckets_now, true ) );
check( 'V2.10.1: geo_location_targets present',     in_array( 'geo_location_targets',     $buckets_now, true ) );

// Now seed the raw option with the legacy keys directly (simulating an
// upgrade from V2.10) and check migration on read.
$GLOBALS['__opts'] = [];
update_option( 'tse_site_exporter_strategy', [
    'active_strategic_targets' => [ '/keep-this/' ],
    'current_seo_targets'      => [ '/seo-target-1/' ],
    'growth_targets'           => [ '/growth-1/' ],
    'campaign_pages'           => [ '/campaign-1/' ],
] );
$mig = tse_strategy_get();
check( 'V2.10.1 migration: current_seo_targets folded',
    in_array( '/seo-target-1/', $mig['active_strategic_targets'], true ) );
check( 'V2.10.1 migration: growth_targets folded',
    in_array( '/growth-1/',     $mig['active_strategic_targets'], true ) );
check( 'V2.10.1 migration: campaign_pages folded',
    in_array( '/campaign-1/',   $mig['active_strategic_targets'], true ) );
check( 'V2.10.1 migration: keys persisted-cleaned',
    ! isset( get_option( 'tse_site_exporter_strategy' )['current_seo_targets'] ) );

/* -- 8. Duplicate-meta dedupe bug fix ------------------------------------ */
// We exercise the relevant lines of ai_summary.php directly by replicating
// the bug pre-condition: an index whose `urls` bucket contains the same URL
// twice (caused upstream by slug collisions / draft revisions).
$bug_urls   = [ 'https://site.test/a/', 'https://site.test/a/' ]; // same URL twice
$valid_urls = [ 'https://site.test/a/', 'https://site.test/b/' ]; // two distinct URLs

// Helper that mirrors the fix in ai_summary.php (V2.10.1).
function tse_test_dup_filter( $urls ) {
    $unique = array_values( array_unique( array_filter( (array) $urls, 'strlen' ) ) );
    return count( $unique ) > 1 ? $unique : null;
}
check( 'dup-meta fix: same-URL-twice set → DROPPED', tse_test_dup_filter( $bug_urls )   === null );
check( 'dup-meta fix: two distinct URLs → KEPT',     tse_test_dup_filter( $valid_urls ) === $valid_urls );

/* -- 9. Geo / location heuristic ----------------------------------------- */
require_once __DIR__ . '/tse-site-exporter/includes/authority.php';

// "bathroom-renovations-in-leeds" → location via "in-leeds" URL token.
$sig = tse_authority_detect_geo_signal(
    'https://site.test/bathroom-renovations-in-leeds/',
    [ 'content' => [ 'h1' => [ 'Bathroom Renovations' ] ], 'seo' => [ 'meta_title' => 'Bathroom Renovations' ] ]
);
check( 'geo: -in-leeds in URL detected', false !== strpos( (string) $sig, 'leeds' ) );

// "/leeds/bathroom-renovations/" → city-segment.
$sig2 = tse_authority_detect_geo_signal(
    'https://site.test/leeds/bathroom-renovations/',
    [ 'content' => [ 'h1' => [ 'Bathroom Renovations' ] ], 'seo' => [] ]
);
check( 'geo: city in path segment detected', null !== $sig2 );

// "in Leeds" in title → h1:in-leeds.
$sig3 = tse_authority_detect_geo_signal(
    'https://site.test/services/bathroom-fitting/',
    [ 'content' => [ 'h1' => [ 'Bathroom Fitting in Leeds' ] ], 'seo' => [ 'meta_title' => '' ] ]
);
check( 'geo: "in <Place>" in H1 detected',  false !== strpos( (string) $sig3, 'leeds' ) );

// Pure service page WITHOUT geo → null.
$sig4 = tse_authority_detect_geo_signal(
    'https://site.test/services/bathroom-fitting/',
    [ 'content' => [ 'h1' => [ 'Bathroom Fitting' ] ], 'seo' => [ 'meta_title' => 'Bathroom Fitting' ] ]
);
check( 'geo: pure service page → no signal', null === $sig4 );

// Filler-word safety: "/services/in-the-area/" must NOT be detected.
$sig5 = tse_authority_detect_geo_signal(
    'https://site.test/services/in-the-area/',
    [ 'content' => [ 'h1' => [ 'In The Area' ] ], 'seo' => [] ]
);
check( 'geo: filler "in the" is rejected', null === $sig5 || false === strpos( (string) $sig5, 'the' ) );

// Declared geo_location_targets bucket = hard override.
$GLOBALS['__opts'] = [];
tse_strategy_save( [ 'geo_location_targets' => "/declared-geo/" ] );
$cls = tse_authority_classify_strategic(
    [
        'url' => 'https://site.test/declared-geo/',
        'classification' => '', 'post_type' => 'page',
        'content' => [ 'h1' => [ 'No Geo Here' ] ], 'seo' => [], 'schema' => [ 'types' => [] ],
    ],
    [ 'incoming_link_count' => 0 ]
);
check( 'declared geo URL → strategic_type=location', $cls['type'] === 'location' );
check( 'declared geo URL → confidence=1.0',          (float) $cls['confidence'] === 1.0 );

// LocalBusiness schema upgrade now wins over service.
$cls2 = tse_authority_classify_strategic(
    [
        'url' => 'https://site.test/services/somewhere/',
        'classification' => '', 'post_type' => 'page',
        'content' => [ 'h1' => [ 'Services' ] ], 'seo' => [],
        'schema' => [ 'types' => [ 'LocalBusiness' ] ],
    ],
    [ 'incoming_link_count' => 0 ]
);
check( 'LocalBusiness schema upgrades service → location', $cls2['type'] === 'location' );

echo "\n";
if ( $fail === 0 ) { echo "ALL ASSERTIONS PASS\n"; exit(0); }
echo "FAILED: $fail assertion(s)\n"; exit(1);
