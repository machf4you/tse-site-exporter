<?php
/**
 * TSE Site Exporter — post-processing pass.
 * Builds anchor-text frequency map, page hierarchy, orphan detection, and broken-link results.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @param array $records   List of PageRecord arrays.
 * @param array $url_index normalized URL => record index.
 * @param array $opts
 * @return array {anchor_freq, hierarchy, orphans, broken_links}
 */
function tse_postprocess_build( $records, $url_index, $opts ) {
    // ---- Anchor text frequency (internal links, normalised lowercase) ----
    $anchor_counts = array();
    foreach ( $records as $r ) {
        foreach ( $r['links']['internal'] as $l ) {
            $key = tse_postprocess_normalise_anchor( $l['anchor'] );
            if ( '' === $key ) continue;
            $anchor_counts[ $key ] = isset( $anchor_counts[ $key ] ) ? $anchor_counts[ $key ] + 1 : 1;
        }
    }
    arsort( $anchor_counts );
    $anchor_freq = array();
    foreach ( $anchor_counts as $anchor => $count ) {
        $anchor_freq[] = array( 'anchor' => $anchor, 'count' => $count );
    }

    // ---- Incoming-link map for orphan detection ----
    $incoming = array();
    foreach ( $records as $r ) {
        foreach ( $r['links']['internal'] as $l ) {
            $norm = tse_normalize_url( $l['url'] );
            if ( '' === $norm ) continue;
            if ( ! empty( $l['is_self'] ) ) continue;
            $incoming[ $norm ] = isset( $incoming[ $norm ] ) ? $incoming[ $norm ] + 1 : 1;
        }
    }

    $orphans = array();
    foreach ( $records as $r ) {
        if ( 'homepage' === $r['classification'] ) continue;
        $norm = tse_normalize_url( $r['url'] );
        if ( empty( $incoming[ $norm ] ) ) {
            $orphans[] = array(
                'id'             => $r['id'],
                'url'            => $r['url'],
                'post_type'      => $r['post_type'],
                'classification' => $r['classification'],
                'title'          => isset( $r['content']['h1'] ) ? $r['content']['h1'] : '',
            );
        }
    }

    // ---- Broken internal link check (optional, HEAD with cache) ----
    $broken_links = array();
    if ( ! empty( $opts['broken_check'] ) ) {
        $checked_cache = array(); // url => status_code|false
        foreach ( $records as $r ) {
            foreach ( $r['links']['internal'] as $l ) {
                $u = $l['url'];
                if ( '' === $u ) continue;
                if ( ! empty( $l['is_self'] ) ) continue;
                $norm = tse_normalize_url( $u );
                if ( ! array_key_exists( $norm, $checked_cache ) ) {
                    $checked_cache[ $norm ] = tse_postprocess_head_status( $u );
                }
                $status = $checked_cache[ $norm ];
                if ( false === $status || $status >= 400 ) {
                    $broken_links[] = array(
                        'source' => $r['url'],
                        'target' => $u,
                        'anchor' => $l['anchor'],
                        'status' => $status === false ? null : $status,
                    );
                }
            }
        }
    }

    // ---- Hierarchy (homepage → money → support → article → other) ----
    $buckets = array(
        'homepage'      => array(),
        'money_pages'   => array(),
        'support_pages' => array(),
        'articles'      => array(),
        'other'         => array(),
    );
    foreach ( $records as $r ) {
        $entry = array(
            'id'        => $r['id'],
            'url'       => $r['url'],
            'title'     => isset( $r['content']['h1'] ) && $r['content']['h1'] !== '' ? $r['content']['h1'] : ( isset( $r['seo']['title'] ) ? $r['seo']['title'] : '' ),
            'post_type' => $r['post_type'],
            'parent_id' => $r['parent_id'],
        );
        switch ( $r['classification'] ) {
            case 'homepage': $buckets['homepage'][]      = $entry; break;
            case 'money':    $buckets['money_pages'][]   = $entry; break;
            case 'support':  $buckets['support_pages'][] = $entry; break;
            case 'article':  $buckets['articles'][]      = $entry; break;
            default:         $buckets['other'][]         = $entry; break;
        }
    }

    $hierarchy = array(
        'description' => 'Authority-flow grouping for AI structure analysis. Order: homepage → money_pages → support_pages → articles → other.',
        'counts' => array(
            'homepage'      => count( $buckets['homepage'] ),
            'money_pages'   => count( $buckets['money_pages'] ),
            'support_pages' => count( $buckets['support_pages'] ),
            'articles'      => count( $buckets['articles'] ),
            'other'         => count( $buckets['other'] ),
        ),
        'groups' => $buckets,
    );

    return array(
        'anchor_freq'  => $anchor_freq,
        'hierarchy'    => $hierarchy,
        'orphans'      => $orphans,
        'broken_links' => $broken_links,
    );
}

function tse_postprocess_normalise_anchor( $text ) {
    if ( ! is_string( $text ) ) return '';
    $t = trim( preg_replace( '/\s+/u', ' ', $text ) );
    return strtolower( $t );
}

function tse_postprocess_head_status( $url ) {
    $response = wp_remote_head( $url, array(
        'timeout'     => 6,
        'redirection' => 3,
        'sslverify'   => false,
        'user-agent'  => 'TSE-Site-Exporter/' . TSE_SITE_EXPORTER_VERSION,
    ) );
    if ( is_wp_error( $response ) ) {
        // Fall back to GET (some servers block HEAD).
        $response = wp_remote_get( $url, array(
            'timeout'     => 6,
            'redirection' => 3,
            'sslverify'   => false,
            'user-agent'  => 'TSE-Site-Exporter/' . TSE_SITE_EXPORTER_VERSION,
        ) );
        if ( is_wp_error( $response ) ) {
            return false;
        }
    }
    return (int) wp_remote_retrieve_response_code( $response );
}
