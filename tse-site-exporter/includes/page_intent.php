<?php
/**
 * TSE Site Exporter — Page intent + indexability + sitemap awareness (V2.10.0).
 *
 * Three orthogonal dimensions are attached to every PageRecord:
 *
 *   - intent       ∈ { seo, utility, legal, conversion, template, gallery }
 *                    Heuristic from URL path, post_type, template slug.
 *                    Drives suppression rules in the recommendation engine.
 *
 *   - indexability ∈ { index, noindex, unknown }
 *                    Read from Yoast and RankMath postmeta first, then from
 *                    the live-rendered <meta name="robots"> tag if available.
 *
 *   - excluded_from_sitemap : bool
 *                    True if the URL is not present in the SEO plugin's
 *                    sitemap (Yoast / RankMath / WP core), fetched once
 *                    per export.
 *
 * These three signals make downstream suppression simple:
 *
 *     a page is "non-SEO" if  intent != seo
 *                          OR indexability == noindex
 *                          OR excluded_from_sitemap == true.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -------------------------------------------------------------------------
 * Page intent classification
 * ---------------------------------------------------------------------- */

/**
 * URL-segment patterns (case-insensitive) for each intent bucket. Order
 * matters — first match wins.
 */
function tse_page_intent_patterns() {
    return array(
        'template'   => array(
            'elementor-library', 'elementor_library', '/elementor-template', '/oembed/', '/wp-json/',
        ),
        'utility'    => array(
            '/login', '/logout', '/register', '/sign-up', '/sign-in',
            '/my-account', '/account', '/cart', '/checkout', '/basket',
            '/search', '/reset-password', '/lost-password', '/password-reset',
            '/wp-admin', '/wp-login', '/feed', '/sitemap', '/dashboard',
            '/customer-area', '/quote-cart',
        ),
        'legal'      => array(
            '/privacy', '/cookie', '/cookies', '/terms', '/gdpr', '/imprint',
            '/legal', '/disclaimer', '/disclosure', '/returns', '/refund',
            '/shipping-policy', '/website-terms', '/terms-of-use', '/terms-and-conditions',
            '/accessibility', '/data-protection',
        ),
        'conversion' => array(
            '/thank-you', '/thanks', '/confirmation', '/order-received', '/success',
            '/quote-sent', '/form-submitted', '/booking-confirmed',
        ),
        'gallery'    => array(
            '/gallery', '/portfolio-item/', '/photos/',
        ),
    );
}

/**
 * Classify a PageRecord. Returns one of the intent enum values.
 *
 * @param array $record A partially-built PageRecord (must have url + post_type + template).
 */
function tse_page_intent_classify( $record ) {
    $url       = strtolower( (string) ( $record['url'] ?? '' ) );
    $post_type = (string) ( $record['post_type'] ?? '' );
    $template  = strtolower( (string) ( $record['template'] ?? '' ) );

    // Post-type fast paths.
    if ( 'elementor_library' === $post_type ) return 'template';
    if ( 'attachment'        === $post_type ) return 'gallery';
    if ( 'oembed_cache'      === $post_type ) return 'template';

    // Template slug fast path.
    if ( '' !== $template ) {
        if ( false !== strpos( $template, 'elementor' ) ) return 'template';
        if ( false !== strpos( $template, 'gallery' ) )   return 'gallery';
    }

    // URL-segment scan (order matters).
    foreach ( tse_page_intent_patterns() as $bucket => $needles ) {
        foreach ( $needles as $needle ) {
            if ( false !== strpos( $url, $needle ) ) return $bucket;
        }
    }

    return 'seo';
}

function tse_page_intent_is_non_seo( $intent ) {
    return in_array( $intent, array( 'utility', 'legal', 'conversion', 'template', 'gallery' ), true );
}

/* -------------------------------------------------------------------------
 * Indexability extraction (Yoast + RankMath + live <meta robots>)
 * ---------------------------------------------------------------------- */

/**
 * Returns one of 'index', 'noindex', 'unknown' for a post ID.
 *
 * @param int    $post_id
 * @param string $live_html optional fetched HTML containing <meta name="robots">.
 */
function tse_page_indexability( $post_id, $live_html = '' ) {
    // Yoast: '0' = use default, '1' = noindex, '2' = index.
    $y = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
    if ( '1' === (string) $y ) return 'noindex';
    if ( '2' === (string) $y ) return 'index';

    // RankMath: 'rank_math_robots' is a serialised array, may contain 'noindex'.
    $rm = get_post_meta( $post_id, 'rank_math_robots', true );
    if ( is_array( $rm ) ) {
        if ( in_array( 'noindex', $rm, true ) ) return 'noindex';
        if ( in_array( 'index',   $rm, true ) ) return 'index';
    } elseif ( is_string( $rm ) && '' !== $rm ) {
        if ( false !== stripos( $rm, 'noindex' ) ) return 'noindex';
        if ( false !== stripos( $rm, 'index' ) )   return 'index';
    }

    // Live HTML <meta name="robots" content="…noindex…">.
    if ( '' !== $live_html && preg_match( '/<meta\s+name=["\']robots["\']\s+content=["\']([^"\']+)["\']/i', $live_html, $m ) ) {
        $content = strtolower( $m[1] );
        if ( false !== strpos( $content, 'noindex' ) ) return 'noindex';
        if ( false !== strpos( $content, 'index' ) )   return 'index';
    }

    return 'unknown';
}

/* -------------------------------------------------------------------------
 * Sitemap awareness
 * ----------------------------------------------------------------------
 * We fetch the SEO plugin's sitemap ONCE per export and cache the URL set.
 * URLs from PageRecords that are NOT in that set get excluded_from_sitemap=true.
 */

/**
 * Pull the URL set from the running site's sitemap, trying Yoast → RankMath →
 * WP core in order. Returns array( 'urls' => Set<string>, 'source' => string ).
 * URLs are normalised path-only (lowercase, trailing slash) so a single set
 * works regardless of host case differences.
 */
function tse_page_sitemap_fetch_url_set() {
    $sources = array(
        home_url( '/sitemap_index.xml' ),  // Yoast / RankMath standard
        home_url( '/wp-sitemap.xml' ),     // WP core
    );

    foreach ( $sources as $url ) {
        $urls = tse_page_sitemap_load_recursive( $url, 0 );
        if ( ! empty( $urls ) ) {
            return array( 'urls' => $urls, 'source' => $url );
        }
    }
    return array( 'urls' => array(), 'source' => '' );
}

/**
 * Load a sitemap URL, follow up to one level of sub-sitemap nesting, and
 * return the normalised path set.
 */
function tse_page_sitemap_load_recursive( $url, $depth ) {
    if ( $depth > 1 ) return array();
    $body = tse_page_sitemap_fetch( $url );
    if ( '' === $body ) return array();

    $out = array();

    // Sub-sitemaps: <sitemap><loc>…</loc></sitemap>
    if ( preg_match_all( '#<sitemap>.*?<loc>([^<]+)</loc>.*?</sitemap>#is', $body, $sm_matches ) ) {
        foreach ( $sm_matches[1] as $child_url ) {
            $child = tse_page_sitemap_load_recursive( trim( $child_url ), $depth + 1 );
            foreach ( $child as $k => $_v ) $out[ $k ] = true;
        }
    }

    // URL entries: <url><loc>…</loc></url>
    if ( preg_match_all( '#<url>.*?<loc>([^<]+)</loc>.*?</url>#is', $body, $u_matches ) ) {
        foreach ( $u_matches[1] as $page_url ) {
            $key = tse_page_sitemap_normalise( $page_url );
            if ( '' !== $key ) $out[ $key ] = true;
        }
    }
    return $out;
}

function tse_page_sitemap_fetch( $url ) {
    if ( ! function_exists( 'wp_remote_get' ) ) return '';
    $resp = wp_remote_get( $url, array(
        'timeout'   => 12,
        'sslverify' => false,
        'headers'   => array( 'Accept' => 'application/xml, text/xml' ),
    ) );
    if ( is_wp_error( $resp ) ) return '';
    $code = (int) wp_remote_retrieve_response_code( $resp );
    if ( $code < 200 || $code >= 400 ) return '';
    return (string) wp_remote_retrieve_body( $resp );
}

function tse_page_sitemap_normalise( $url ) {
    $url = trim( html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ) );
    if ( '' === $url ) return '';
    $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
    $path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
    if ( '' === $path ) $path = '/';
    if ( '/' !== $path[0] ) $path = '/' . $path;
    $path = strtolower( $path );
    if ( '/' !== substr( $path, -1 ) ) $path .= '/';
    return $path;
}

/**
 * Returns true if the given page URL is NOT in the supplied sitemap URL set.
 * If the set is empty (sitemap couldn't be fetched), returns null (= unknown).
 */
function tse_page_sitemap_is_excluded( $page_url, $sitemap_set ) {
    if ( empty( $sitemap_set ) ) return null;
    $key = tse_page_sitemap_normalise( $page_url );
    if ( '' === $key ) return null;
    return ! isset( $sitemap_set[ $key ] );
}
