<?php
/**
 * TSE Site Exporter — Unified issue normaliser (V2.10.0).
 *
 * Takes the raw items produced by the 4 AI prompts AND the deterministic
 * strategy-mismatch items, and returns a single normalised list:
 *
 *   {
 *     id, group, severity, action_type, intent_filter,
 *     affected_pages, recommendation, implementation_guidance, confidence,
 *     source                      // which prompt / module produced this
 *   }
 *
 * Where:
 *   group        ∈ Metadata | Linking | Cannibalisation | Thin Content |
 *                  Architecture | Authority | Strategy | Other
 *   severity     ∈ high | medium | low                (renamed from priority)
 *   action_type  ∈ content_admin | developer_technical
 *
 * Three suppression rules are applied using the per-page intent + indexability
 * lookup (built from PageRecords by the caller):
 *
 *   - thin-content / metadata items on non-SEO or noindex pages → DROPPED.
 *   - internal-linking items where source or target is non-SEO / noindex / excluded
 *     from sitemap → DROPPED (we don't want SEO suggestions on system pages).
 *   - other items (canonicalisation, schema warnings) → kept but tagged.
 *
 * A dedupe pass collapses items emitted by multiple prompts using the key
 *   (group | sorted affected_pages tuple).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Build a per-URL lookup of intent / indexability / sitemap-exclusion from
 * either raw PageRecords or the slim ai-page-summaries records. Both shapes
 * are accepted because the AI runner sees the slim version.
 *
 * Result: array<string normalised_path, array{intent, indexability, excluded_from_sitemap}>.
 */
function tse_issues_build_page_lookup( $pages ) {
    $out = array();
    if ( ! is_array( $pages ) ) return $out;
    foreach ( $pages as $p ) {
        $url = isset( $p['url'] ) ? (string) $p['url'] : '';
        if ( '' === $url ) continue;
        $k = tse_issues_norm( $url );
        $intent = $p['intent']
            ?? ( $p['strategic_type'] ?? 'seo' );
        $index  = $p['indexability'] ?? 'unknown';
        $excl   = isset( $p['excluded_from_sitemap'] ) ? (bool) $p['excluded_from_sitemap'] : null;
        $out[ $k ] = array(
            'url'                   => $url,
            'intent'                => $intent,
            'indexability'          => $index,
            'excluded_from_sitemap' => $excl,
        );
    }
    return $out;
}

function tse_issues_norm( $url ) {
    if ( '' === $url ) return '';
    $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
    $path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
    if ( '' === $path ) $path = '/';
    if ( '/' !== $path[0] ) $path = '/' . $path;
    $path = preg_replace( '/[#?].*$/', '', $path );
    $path = strtolower( $path );
    if ( '/' !== substr( $path, -1 ) ) $path .= '/';
    return $path;
}

/* -------------------------------------------------------------------------
 * Group + action classification
 * ---------------------------------------------------------------------- */

function tse_issues_group_for( $item ) {
    $cat = strtolower( (string) ( $item['category'] ?? '' ) );
    $gt  = strtolower( (string) ( $item['gap_type'] ?? '' ) );
    $ft  = strtolower( (string) ( $item['finding_type'] ?? '' ) );
    $issue = strtolower( (string) ( $item['issue'] ?? '' ) );
    $rec   = strtolower( (string) ( $item['recommendation'] ?? '' ) );
    $hay   = $cat . ' ' . $gt . ' ' . $issue . ' ' . $rec;

    if ( 'strategy'        === $cat ) return 'Strategy';
    if ( 'metadata'        === $cat || 'metadata_weak' === $gt ) return 'Metadata';
    if ( 'linking'         === $cat ) return 'Linking';
    if ( 'cannibalisation' === $cat || 'cannibalization' === $cat
      || in_array( $gt, array( 'cannibalisation', 'cannibalization', 'topic_overlap' ), true )
      || false !== strpos( $hay, 'cannibal' ) ) return 'Cannibalisation';
    if ( 'content'   === $cat || 'thin_content' === $gt ) return 'Thin Content';
    if ( 'authority' === $cat ) return 'Authority';
    if ( 'cluster'   === $cat || 'isolated' === $ft ) return 'Architecture';
    if ( in_array( $gt, array( 'missing_support', 'missing_money' ), true ) ) return 'Architecture';
    if ( false !== strpos( $hay, 'meta title' ) || false !== strpos( $hay, 'meta description' ) ) return 'Metadata';
    if ( false !== strpos( $hay, 'internal link' ) || false !== strpos( $hay, 'orphan' ) || isset( $item['source_url'] ) ) return 'Linking';
    if ( false !== strpos( $hay, 'thin' ) || false !== strpos( $hay, 'word count' ) ) return 'Thin Content';
    return 'Other';
}

/**
 * Tag each item as a content/admin task or a developer/technical task.
 * Defaults err on the side of content_admin so non-technical users see
 * everything they can act on.
 */
function tse_issues_action_type_for( $group, $item ) {
    $hay = strtolower( (string) ( ( $item['issue'] ?? '' ) . ' ' . ( $item['recommendation'] ?? '' ) ) );

    // Hard developer signals.
    $dev_signals = array(
        'schema', 'json-ld', 'json ld', 'robots', 'canonical',
        'redirect', '301', '302', 'noindex', 'sitemap',
        'template', 'elementor template', 'theme', 'php', 'http header',
        'hreflang', 'meta refresh',
    );
    foreach ( $dev_signals as $signal ) {
        if ( false !== strpos( $hay, $signal ) ) return 'developer_technical';
    }
    if ( in_array( $group, array( 'Architecture' ), true ) ) {
        return 'developer_technical';
    }
    // Everything else is plain content work.
    return 'content_admin';
}

/* -------------------------------------------------------------------------
 * Suppression rules
 * ---------------------------------------------------------------------- */

/**
 * Return true if the item should be DROPPED based on intent / indexability
 * of the affected pages.
 */
function tse_issues_should_suppress( $group, $item, $lookup ) {
    $pages = (array) ( $item['affected_pages'] ?? array() );

    // Linking items: drop if EITHER source or target is non-SEO / noindex /
    // excluded from sitemap.
    if ( 'Linking' === $group ) {
        $src = (string) ( $item['source_url'] ?? ( $pages[0] ?? '' ) );
        $tgt = (string) ( $item['target_url'] ?? ( $pages[1] ?? '' ) );
        foreach ( array( $src, $tgt ) as $u ) {
            if ( '' === $u ) continue;
            $info = $lookup[ tse_issues_norm( $u ) ] ?? null;
            if ( ! $info ) continue;
            if ( tse_issues_is_non_seo( $info ) ) return true;
        }
        return false;
    }

    // Metadata + Thin Content: drop only if ALL affected pages are non-SEO.
    if ( in_array( $group, array( 'Metadata', 'Thin Content' ), true ) ) {
        if ( empty( $pages ) ) return false;
        $all_non_seo = true;
        foreach ( $pages as $u ) {
            $info = $lookup[ tse_issues_norm( $u ) ] ?? null;
            if ( ! $info ) { $all_non_seo = false; break; }
            if ( ! tse_issues_is_non_seo( $info ) ) { $all_non_seo = false; break; }
        }
        return $all_non_seo;
    }

    return false;
}

function tse_issues_is_non_seo( $info ) {
    $intent = (string) ( $info['intent'] ?? 'seo' );
    $idx    = (string) ( $info['indexability'] ?? 'unknown' );
    $excl   = $info['excluded_from_sitemap'] ?? null;
    if ( in_array( $intent, array( 'utility', 'legal', 'conversion', 'template', 'gallery' ), true ) ) return true;
    if ( 'noindex' === $idx ) return true;
    if ( true === $excl ) return true;
    return false;
}

/* -------------------------------------------------------------------------
 * Normalise + suppress + dedupe — public entry.
 * ---------------------------------------------------------------------- */

function tse_issues_normalise( $raw_items_by_source, $page_lookup ) {
    $out  = array();
    $seen = array();
    $next = 1;

    foreach ( $raw_items_by_source as $source => $items ) {
        if ( ! is_array( $items ) ) continue;
        foreach ( $items as $item ) {
            if ( ! is_array( $item ) ) continue;
            $group       = tse_issues_group_for( $item );
            if ( tse_issues_should_suppress( $group, $item, $page_lookup ) ) continue;

            $severity    = strtolower( (string) ( $item['priority'] ?? '' ) );
            if ( ! in_array( $severity, array( 'high', 'medium', 'low' ), true ) ) $severity = 'low';

            $pages = array_values( array_unique( array_filter( (array) ( $item['affected_pages'] ?? array() ), 'strlen' ) ) );

            $key = $group . '|' . implode( ',', array_map( 'tse_issues_norm', $pages ) ) . '|'
                 . substr( strtolower( (string) ( $item['issue'] ?? '' ) ), 0, 40 );
            if ( isset( $seen[ $key ] ) ) {
                // Merge: keep highest severity, highest confidence, union sources.
                $i = $seen[ $key ];
                $out[ $i ]['severity']   = tse_issues_max_severity( $out[ $i ]['severity'], $severity );
                $out[ $i ]['confidence'] = max( $out[ $i ]['confidence'], (float) ( $item['confidence_score'] ?? 0 ) );
                if ( ! in_array( $source, $out[ $i ]['source'], true ) ) $out[ $i ]['source'][] = $source;
                continue;
            }

            $action_type = tse_issues_action_type_for( $group, $item );
            $impl_guidance = (string) ( $item['implementation_guidance'] ?? '' );
            if ( '' === $impl_guidance ) $impl_guidance = tse_issues_default_guidance( $group, $item );

            $out[] = array(
                'id'                      => 'iss_' . str_pad( (string) $next, 4, '0', STR_PAD_LEFT ),
                'group'                   => $group,
                'severity'                => $severity,
                'action_type'             => $action_type,
                'intent_filter'           => tse_issues_intent_summary( $pages, $page_lookup ),
                'issue'                   => (string) ( $item['issue'] ?? '' ),
                'affected_pages'          => $pages,
                'recommendation'          => (string) ( $item['recommendation'] ?? '' ),
                'implementation_guidance' => $impl_guidance,
                'confidence'              => (float) ( $item['confidence_score'] ?? 0 ),
                'source'                  => array( (string) $source ),
                // Preserve link-specific fields for the card renderer.
                'source_url'              => (string) ( $item['source_url'] ?? '' ),
                'target_url'              => (string) ( $item['target_url'] ?? '' ),
                'suggested_anchor'        => (string) ( $item['suggested_anchor'] ?? '' ),
                'reason'                  => (string) ( $item['reason'] ?? '' ),
            );
            $seen[ $key ] = count( $out ) - 1;
            $next++;
        }
    }
    // Stable sort: severity desc, group, confidence desc.
    $rank = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
    usort( $out, function( $a, $b ) use ( $rank ) {
        $ra = $rank[ $a['severity'] ] ?? 3;
        $rb = $rank[ $b['severity'] ] ?? 3;
        if ( $ra !== $rb ) return $ra <=> $rb;
        if ( $a['group'] !== $b['group'] ) return strcmp( $a['group'], $b['group'] );
        return $b['confidence'] <=> $a['confidence'];
    } );
    return $out;
}

function tse_issues_max_severity( $a, $b ) {
    $rank = array( 'high' => 3, 'medium' => 2, 'low' => 1 );
    return ( ( $rank[ $a ] ?? 0 ) >= ( $rank[ $b ] ?? 0 ) ) ? $a : $b;
}

function tse_issues_intent_summary( $pages, $lookup ) {
    $intents = array();
    foreach ( $pages as $u ) {
        $info = $lookup[ tse_issues_norm( $u ) ] ?? null;
        if ( ! $info ) continue;
        $intents[ $info['intent'] ] = true;
    }
    return array_keys( $intents );
}

function tse_issues_default_guidance( $group, $item ) {
    switch ( $group ) {
        case 'Linking':
            return 'Open the source page in the editor, find a contextually relevant sentence, and add an inline link to the target page using the suggested anchor. Save and clear page cache.';
        case 'Metadata':
            return 'Edit the page in WordPress, scroll to the Yoast / Rank Math metabox, rewrite the meta field, save. Allow up to 24h for Google to recrawl.';
        case 'Cannibalisation':
            return 'Decide which page is the canonical winner. Either merge content into the winner and 301 the loser, or differentiate intent by rewriting the loser to target a different query.';
        case 'Thin Content':
            return 'Expand the body to comfortably exceed 300 words. Add FAQ-style headings, examples, and at least one image with descriptive alt text.';
        case 'Architecture':
            return 'Treat as a structural change. Coordinate with a developer or theme owner — may involve menu / template / sitemap edits.';
        case 'Strategy':
            return 'Reflect this finding in your editorial backlog: it represents declared business intent that the current internal architecture is not honouring.';
        default:
            return '';
    }
}

/* -------------------------------------------------------------------------
 * Splitter — Content/Admin track vs Developer/Technical track.
 * ---------------------------------------------------------------------- */
function tse_issues_split_tracks( $issues ) {
    $content = array(); $dev = array();
    foreach ( $issues as $i ) {
        if ( 'developer_technical' === $i['action_type'] ) $dev[] = $i;
        else $content[] = $i;
    }
    return array( 'content_admin' => $content, 'developer_technical' => $dev );
}
