<?php
/**
 * TSE Site Exporter — AI Analysis Layer (V2.4.0).
 *
 * Consumes the full PageRecord set + relationships + authority output and
 * produces compact, token-economical, AI-analysis-ready datasets:
 *
 *   ai-site-summary.json     — totals, distributions, top hubs / authorities,
 *                              high-level issue counts, coverage flags.
 *   ai-page-summaries.json   — one slim record per page (no Elementor, no raw
 *                              HTML, no plain_text), with per-page issue flags
 *                              and top inbound / outbound anchors.
 *   ai-linking-summary.json  — weak money/service/location/product pages,
 *                              orphan/near-orphan pages, under-supported
 *                              clusters, internal-linking opportunities
 *                              (suggested source → target candidates).
 *   ai-cluster-summary.json  — main vs isolated clusters with member URLs,
 *                              dominant types, and recommended bridge sources.
 *
 * Backend-only, deterministic. No LLM / external calls.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry. Returns:
 *  [ 'files' => filename => payload ]
 */
function tse_ai_summary_build( $records, $relationships, $authority, $postprocess ) {
    $by_norm        = array();
    $url_meta_by_id = array();
    foreach ( $records as $i => $r ) {
        $by_norm[ tse_normalize_url( $r['url'] ) ] = $i;
    }

    // ---- Inverse index: cluster_id per page norm ---------------------------
    $cluster_by_norm = array();
    $cluster_meta    = array();
    foreach ( $authority['cluster_signals']['clusters'] as $c ) {
        $cluster_meta[ $c['cluster_id'] ] = array(
            'cluster_id'    => $c['cluster_id'],
            'size'          => $c['size'],
            'is_main'       => $c['is_main'],
            'is_isolated'   => $c['is_isolated'],
            'dominant_type' => $c['dominant_type'],
        );
        foreach ( $c['members'] as $m ) $cluster_by_norm[ $m ] = $c['cluster_id'];
    }

    // ---- Pre-index: incoming edges per target with source meta ------------
    $incoming_edges = array();
    foreach ( $authority['weighted_graph']['edges'] as $e ) {
        $tnorm = tse_normalize_url( $e['target'] );
        $incoming_edges[ $tnorm ][] = $e;
    }
    $outgoing_edges = array();
    foreach ( $authority['weighted_graph']['edges'] as $e ) {
        $snorm = tse_normalize_url( $e['source'] );
        $outgoing_edges[ $snorm ][] = $e;
    }

    // ---- 1. Per-page slim summaries ---------------------------------------
    $page_summaries = array();
    $meta_title_index = array();
    $meta_desc_index  = array();

    foreach ( $records as $r ) {
        $norm  = tse_normalize_url( $r['url'] );
        $auth  = isset( $r['authority'] )     ? $r['authority']     : ( isset( $authority['per_page'][ $norm ] ) ? $authority['per_page'][ $norm ] : array() );
        $rel   = isset( $r['relationships'] ) ? $r['relationships'] : ( isset( $relationships['per_page'][ $norm ] ) ? $relationships['per_page'][ $norm ] : array() );
        $seo   = isset( $r['seo'] )           ? $r['seo']           : array();
        $cont  = isset( $r['content'] )       ? $r['content']       : array();

        $h1         = isset( $cont['h1'] )         ? (string) $cont['h1']         : '';
        $h2_list    = isset( $cont['h2'] )         ? array_slice( (array) $cont['h2'], 0, 10 ) : array();
        $h3_count   = isset( $cont['h3'] )         ? count( (array) $cont['h3'] ) : 0;
        $word_count = isset( $cont['word_count'] ) ? (int) $cont['word_count']    : 0;

        $title = '' !== $h1 ? $h1 : ( isset( $seo['title'] ) ? (string) $seo['title'] : '' );

        // Top inbound / outbound anchors (top 3 each, normalised).
        $in_anchors  = array();
        $out_anchors = array();
        $top_inbound_sources  = array();
        $top_outbound_targets = array();

        if ( isset( $incoming_edges[ $norm ] ) ) {
            $anc = array();
            $src = array();
            foreach ( $incoming_edges[ $norm ] as $e ) {
                $a = tse_postprocess_normalise_anchor( $e['anchor'] );
                if ( '' !== $a ) $anc[ $a ] = ( isset( $anc[ $a ] ) ? $anc[ $a ] + 1 : 1 );
                $src[ $e['source'] ] = ( isset( $src[ $e['source'] ] ) ? $src[ $e['source'] ] + 1 : 1 );
            }
            arsort( $anc );
            arsort( $src );
            $in_anchors = array_slice( array_keys( $anc ), 0, 3 );
            $top_inbound_sources = array_slice( array_keys( $src ), 0, 3 );
        }
        if ( isset( $outgoing_edges[ $norm ] ) ) {
            $anc = array();
            $tgt = array();
            foreach ( $outgoing_edges[ $norm ] as $e ) {
                $a = tse_postprocess_normalise_anchor( $e['anchor'] );
                if ( '' !== $a ) $anc[ $a ] = ( isset( $anc[ $a ] ) ? $anc[ $a ] + 1 : 1 );
                $tgt[ $e['target'] ] = ( isset( $tgt[ $e['target'] ] ) ? $tgt[ $e['target'] ] + 1 : 1 );
            }
            arsort( $anc );
            arsort( $tgt );
            $out_anchors = array_slice( array_keys( $anc ), 0, 3 );
            $top_outbound_targets = array_slice( array_keys( $tgt ), 0, 3 );
        }

        // Issue flags (deterministic, no LLM).
        $issues = tse_ai_detect_page_issues( $r, $seo, $cont, $auth, $rel, $authority );

        // Track for duplicate detection.
        $mt = isset( $seo['title'] )       ? trim( (string) $seo['title'] )       : '';
        $md = isset( $seo['description'] ) ? trim( (string) $seo['description'] ) : '';
        if ( '' !== $mt ) $meta_title_index[ strtolower( $mt ) ][] = $r['url'];
        if ( '' !== $md ) $meta_desc_index[ strtolower( $md ) ][]  = $r['url'];

        $page_summaries[] = array(
            'id'                          => (int) $r['id'],
            'url'                         => (string) $r['url'],
            'title'                       => $title,
            'meta_title'                  => $mt,
            'meta_description'            => $md,
            'classification'              => isset( $r['classification'] ) ? (string) $r['classification'] : '',
            'strategic_type'              => isset( $auth['strategic_type'] ) ? $auth['strategic_type'] : 'other',
            'strategic_confidence'        => isset( $auth['strategic_confidence'] ) ? $auth['strategic_confidence'] : 0,
            // V2.10 — page intent + indexability + sitemap awareness.
            'intent'                      => isset( $r['intent'] ) ? (string) $r['intent'] : 'seo',
            'indexability'                => isset( $r['indexability'] ) ? (string) $r['indexability'] : 'unknown',
            'excluded_from_sitemap'       => array_key_exists( 'excluded_from_sitemap', $r ) ? $r['excluded_from_sitemap'] : null,
            'h1'                          => $h1,
            'h2'                          => array_values( $h2_list ),
            'h3_count'                    => $h3_count,
            'word_count'                  => $word_count,
            'internal_authority_score'    => isset( $auth['internal_authority_score'] )    ? $auth['internal_authority_score']    : 0,
            'relationship_strength_score' => isset( $auth['relationship_strength_score'] ) ? $auth['relationship_strength_score'] : 0,
            'contextual_support_score'    => isset( $auth['contextual_support_score'] )    ? $auth['contextual_support_score']    : 0,
            'incoming_link_quality_score' => isset( $auth['incoming_link_quality_score'] ) ? $auth['incoming_link_quality_score'] : 0,
            'incoming_link_count'         => isset( $rel['incoming_link_count'] )  ? (int) $rel['incoming_link_count']  : 0,
            'outgoing_link_count'         => isset( $rel['outgoing_link_count'] )  ? (int) $rel['outgoing_link_count']  : 0,
            'unique_linking_pages'        => isset( $rel['unique_linking_pages'] ) ? (int) $rel['unique_linking_pages'] : 0,
            'cluster_id'                  => isset( $cluster_by_norm[ $norm ] ) ? $cluster_by_norm[ $norm ] : null,
            'is_isolated'                 => isset( $cluster_by_norm[ $norm ], $cluster_meta[ $cluster_by_norm[ $norm ] ] )
                ? (bool) $cluster_meta[ $cluster_by_norm[ $norm ] ]['is_isolated']
                : false,
            'top_inbound_anchors'         => $in_anchors,
            'top_outbound_anchors'        => $out_anchors,
            'top_inbound_sources'         => $top_inbound_sources,
            'top_outbound_targets'        => $top_outbound_targets,
            'issues'                      => $issues,
        );
    }

    // ---- 2. Site-wide summary ---------------------------------------------
    $by_classification = array();
    $by_strategic      = array();
    $auth_values       = array();
    $issue_counts      = array();
    foreach ( $page_summaries as $p ) {
        $by_classification[ $p['classification'] ] = ( isset( $by_classification[ $p['classification'] ] ) ? $by_classification[ $p['classification'] ] + 1 : 1 );
        $by_strategic[ $p['strategic_type'] ]      = ( isset( $by_strategic[ $p['strategic_type'] ] )      ? $by_strategic[ $p['strategic_type'] ] + 1      : 1 );
        $auth_values[] = $p['internal_authority_score'];
        foreach ( $p['issues'] as $iss ) {
            $issue_counts[ $iss ] = ( isset( $issue_counts[ $iss ] ) ? $issue_counts[ $iss ] + 1 : 1 );
        }
    }
    sort( $auth_values );
    $avg_auth    = $auth_values ? round( array_sum( $auth_values ) / count( $auth_values ), 2 ) : 0;
    $median_auth = $auth_values ? $auth_values[ (int) ( count( $auth_values ) / 2 ) ] : 0;

    // Top 5 by authority / outgoing (compact form).
    $by_auth = $page_summaries;
    usort( $by_auth, function( $a, $b ) { return $a['internal_authority_score'] < $b['internal_authority_score'] ? 1 : ( $a['internal_authority_score'] > $b['internal_authority_score'] ? -1 : 0 ); } );
    $top_authorities = array();
    foreach ( array_slice( $by_auth, 0, 5 ) as $p ) {
        $top_authorities[] = array(
            'url' => $p['url'], 'strategic_type' => $p['strategic_type'],
            'internal_authority_score' => $p['internal_authority_score'],
            'incoming_link_count' => $p['incoming_link_count'],
        );
    }
    $by_out = $page_summaries;
    usort( $by_out, function( $a, $b ) { return $b['outgoing_link_count'] - $a['outgoing_link_count']; } );
    $top_hubs = array();
    foreach ( array_slice( $by_out, 0, 5 ) as $p ) {
        $top_hubs[] = array(
            'url' => $p['url'], 'strategic_type' => $p['strategic_type'],
            'outgoing_link_count' => $p['outgoing_link_count'],
            'unique_target_pages' => isset( $relationships['per_page'][ tse_normalize_url( $p['url'] ) ]['unique_target_pages'] ) ? $relationships['per_page'][ tse_normalize_url( $p['url'] ) ]['unique_target_pages'] : 0,
        );
    }

    $site_summary = array(
        'site' => array(
            'url'           => home_url(),
            'name'          => get_bloginfo( 'name' ),
            'plugin_version'=> TSE_SITE_EXPORTER_VERSION,
            'generated_at'  => gmdate( 'c' ),
        ),
        'totals' => array(
            'pages'              => count( $page_summaries ),
            'internal_edges'     => isset( $authority['weighted_graph']['totals']['edges'] ) ? (int) $authority['weighted_graph']['totals']['edges'] : 0,
            'clusters'           => isset( $authority['cluster_signals']['totals']['clusters'] ) ? (int) $authority['cluster_signals']['totals']['clusters'] : 0,
            'isolated_clusters'  => isset( $authority['cluster_signals']['totals']['isolated_clusters'] ) ? (int) $authority['cluster_signals']['totals']['isolated_clusters'] : 0,
        ),
        'distribution' => array(
            'by_classification' => $by_classification,
            'by_strategic_type' => $by_strategic,
        ),
        'authority' => array(
            'average' => $avg_auth,
            'median'  => $median_auth,
        ),
        'top_authorities' => $top_authorities,
        'top_hubs'        => $top_hubs,
        'issue_counts'    => $issue_counts,
    );

    // ---- 3. Linking summary -----------------------------------------------
    $important_types = array( 'money', 'service', 'location', 'product', 'category' );

    // Authority lookup for source candidate ranking.
    $auth_lookup = array();
    foreach ( $page_summaries as $p ) {
        $auth_lookup[ tse_normalize_url( $p['url'] ) ] = $p;
    }

    // Current edge set for duplicate-link suppression.
    $edge_set = array();
    foreach ( $authority['weighted_graph']['edges'] as $e ) {
        $edge_set[ tse_normalize_url( $e['source'] ) . '|' . tse_normalize_url( $e['target'] ) ] = true;
    }

    $weak_money_pages  = array();
    $orphans           = array();
    $near_orphans      = array();
    $opportunities     = array();

    foreach ( $page_summaries as $p ) {
        $norm = tse_normalize_url( $p['url'] );
        $is_homepage = ( 'homepage' === $p['classification'] );

        if ( ! $is_homepage && $p['incoming_link_count'] === 0 ) {
            $orphans[] = array(
                'url' => $p['url'], 'strategic_type' => $p['strategic_type'],
                'classification' => $p['classification'],
                'issues' => $p['issues'],
            );
        } elseif ( ! $is_homepage && $p['incoming_link_count'] === 1 ) {
            $near_orphans[] = array(
                'url' => $p['url'], 'strategic_type' => $p['strategic_type'],
                'classification' => $p['classification'],
                'inbound_source' => $p['top_inbound_sources'] ? $p['top_inbound_sources'][0] : null,
            );
        }

        if ( in_array( $p['strategic_type'], $important_types, true ) && $p['internal_authority_score'] <= $median_auth ) {
            $weak_money_pages[] = array(
                'url'                       => $p['url'],
                'strategic_type'            => $p['strategic_type'],
                'internal_authority_score'  => $p['internal_authority_score'],
                'incoming_link_count'       => $p['incoming_link_count'],
                'median_authority'          => $median_auth,
            );

            // Suggest up to 3 high-authority same-strategic-type pages that
            // are not currently linking to this target.
            $candidates = array();
            foreach ( $page_summaries as $cand ) {
                if ( $cand['url'] === $p['url'] ) continue;
                $cnorm = tse_normalize_url( $cand['url'] );
                if ( isset( $edge_set[ $cnorm . '|' . $norm ] ) ) continue;
                if ( $cand['strategic_type'] !== $p['strategic_type']
                  && 'homepage'              !== $cand['strategic_type'] ) continue;
                $candidates[] = $cand;
            }
            usort( $candidates, function( $a, $b ) { return $a['internal_authority_score'] < $b['internal_authority_score'] ? 1 : ( $a['internal_authority_score'] > $b['internal_authority_score'] ? -1 : 0 ); } );
            foreach ( array_slice( $candidates, 0, 3 ) as $c ) {
                $opportunities[] = array(
                    'source_url'              => $c['url'],
                    'source_authority'        => $c['internal_authority_score'],
                    'source_strategic_type'   => $c['strategic_type'],
                    'target_url'              => $p['url'],
                    'target_strategic_type'   => $p['strategic_type'],
                    'target_authority'        => $p['internal_authority_score'],
                    'reason'                  => 'under-supported ' . $p['strategic_type']
                                                 . ' page; suggested source has high authority and matching strategic type',
                );
            }
        }
    }

    // Duplicate metadata detection.
    $dup_titles = array();
    foreach ( $meta_title_index as $title => $urls ) {
        if ( count( $urls ) > 1 ) $dup_titles[] = array( 'meta_title' => $title, 'count' => count( $urls ), 'urls' => $urls );
    }
    $dup_descs = array();
    foreach ( $meta_desc_index as $desc => $urls ) {
        if ( count( $urls ) > 1 ) $dup_descs[] = array( 'meta_description' => $desc, 'count' => count( $urls ), 'urls' => $urls );
    }

    // Under-supported clusters: any cluster that is isolated OR dominated by
    // important strategic types with mostly low authority.
    $under_supported_clusters = array();
    foreach ( $authority['cluster_signals']['clusters'] as $c ) {
        if ( $c['is_main'] ) continue;
        $member_auth = array();
        foreach ( $c['members'] as $m ) {
            if ( isset( $auth_lookup[ $m ] ) ) $member_auth[] = $auth_lookup[ $m ]['internal_authority_score'];
        }
        $avg = $member_auth ? round( array_sum( $member_auth ) / count( $member_auth ), 2 ) : 0;
        $under_supported_clusters[] = array(
            'cluster_id'    => $c['cluster_id'],
            'size'          => $c['size'],
            'dominant_type' => $c['dominant_type'],
            'average_authority' => $avg,
            'members'       => $c['members'],
        );
    }

    $linking_summary = array(
        'description' => 'AI-ready linking insights derived from the relationship + authority engines.',
        'weak_money_pages'           => $weak_money_pages,
        'orphan_pages'               => $orphans,
        'near_orphan_pages'          => $near_orphans,
        'under_supported_clusters'   => $under_supported_clusters,
        'duplicate_meta_titles'      => $dup_titles,
        'duplicate_meta_descriptions'=> $dup_descs,
        'linking_opportunities'      => $opportunities,
        'thresholds' => array(
            'important_strategic_types' => $important_types,
            'median_authority'          => $median_auth,
        ),
    );

    // ---- 4. Cluster summary ------------------------------------------------
    // Build top authority lookup for main cluster (used as bridge candidates).
    $main_top_by_type = array();
    foreach ( $authority['cluster_signals']['clusters'] as $c ) {
        if ( ! $c['is_main'] ) continue;
        $by_type_pages = array();
        foreach ( $c['members'] as $m ) {
            if ( ! isset( $auth_lookup[ $m ] ) ) continue;
            $p = $auth_lookup[ $m ];
            $by_type_pages[ $p['strategic_type'] ][] = $p;
        }
        foreach ( $by_type_pages as $t => $arr ) {
            usort( $arr, function( $a, $b ) { return $a['internal_authority_score'] < $b['internal_authority_score'] ? 1 : ( $a['internal_authority_score'] > $b['internal_authority_score'] ? -1 : 0 ); } );
            $main_top_by_type[ $t ] = $arr[0];
        }
        break;
    }

    $clusters_out = array();
    foreach ( $authority['cluster_signals']['clusters'] as $c ) {
        $bridge = null;
        if ( ! $c['is_main'] ) {
            $target_type = $c['dominant_type'];
            if ( isset( $main_top_by_type[ $target_type ] ) ) {
                $bridge = array(
                    'source_url'            => $main_top_by_type[ $target_type ]['url'],
                    'source_strategic_type' => $target_type,
                    'source_authority'      => $main_top_by_type[ $target_type ]['internal_authority_score'],
                    'rationale'             => 'top main-cluster page of matching strategic type; bridges isolated cluster into the main graph.',
                );
            } elseif ( ! empty( $main_top_by_type['homepage'] ) ) {
                $bridge = array(
                    'source_url'            => $main_top_by_type['homepage']['url'],
                    'source_strategic_type' => 'homepage',
                    'source_authority'      => $main_top_by_type['homepage']['internal_authority_score'],
                    'rationale'             => 'no main-cluster page of matching strategic type found; homepage fallback bridge.',
                );
            }
        }
        $member_auth = array();
        foreach ( $c['members'] as $m ) {
            if ( isset( $auth_lookup[ $m ] ) ) $member_auth[] = $auth_lookup[ $m ]['internal_authority_score'];
        }
        $avg = $member_auth ? round( array_sum( $member_auth ) / count( $member_auth ), 2 ) : 0;
        $clusters_out[] = array(
            'cluster_id'        => $c['cluster_id'],
            'size'              => $c['size'],
            'is_main'           => $c['is_main'],
            'is_isolated'       => $c['is_isolated'],
            'dominant_type'     => $c['dominant_type'],
            'type_breakdown'    => $c['type_breakdown'],
            'average_authority' => $avg,
            'members'           => $c['members'],
            'recommended_bridge'=> $bridge,
        );
    }

    $cluster_summary = array(
        'description' => 'Cluster-level AI summary: main cluster, isolated clusters, recommended bridge sources from the main graph.',
        'totals'      => array(
            'clusters'          => count( $clusters_out ),
            'isolated_clusters' => isset( $authority['cluster_signals']['totals']['isolated_clusters'] ) ? (int) $authority['cluster_signals']['totals']['isolated_clusters'] : 0,
            'main_cluster_size' => isset( $authority['cluster_signals']['totals']['main_cluster_size'] ) ? (int) $authority['cluster_signals']['totals']['main_cluster_size'] : 0,
        ),
        'clusters' => $clusters_out,
    );

    return array(
        'files' => array(
            'ai-site-summary.json'    => $site_summary,
            'ai-page-summaries.json'  => $page_summaries,
            'ai-linking-summary.json' => $linking_summary,
            'ai-cluster-summary.json' => $cluster_summary,
        ),
    );
}

/* -------------------------------------------------------------------------
 * Per-page deterministic issue detection
 * ---------------------------------------------------------------------- */
function tse_ai_detect_page_issues( $r, $seo, $cont, $auth, $rel, $authority ) {
    $issues = array();

    $title = isset( $seo['title'] )       ? trim( (string) $seo['title'] )       : '';
    $desc  = isset( $seo['description'] ) ? trim( (string) $seo['description'] ) : '';
    if ( '' === $title ) $issues[] = 'missing_meta_title';
    if ( '' === $desc )  $issues[] = 'missing_meta_description';
    if ( '' !== $title && mb_strlen( $title ) < 20 ) $issues[] = 'short_meta_title';
    if ( '' !== $desc  && mb_strlen( $desc )  < 70 ) $issues[] = 'short_meta_description';

    $h1 = isset( $cont['h1'] ) ? trim( (string) $cont['h1'] ) : '';
    if ( '' === $h1 || mb_strlen( $h1 ) < 5 ) $issues[] = 'weak_h1';

    $word_count = isset( $cont['word_count'] ) ? (int) $cont['word_count'] : 0;
    if ( $word_count > 0 && $word_count < 300 ) $issues[] = 'thin_content';

    $is_homepage = isset( $r['classification'] ) && 'homepage' === $r['classification'];
    $in_count    = isset( $rel['incoming_link_count'] ) ? (int) $rel['incoming_link_count'] : 0;
    $out_count   = isset( $rel['outgoing_link_count'] ) ? (int) $rel['outgoing_link_count'] : 0;
    if ( ! $is_homepage && $in_count === 0 ) $issues[] = 'no_incoming_links';
    elseif ( ! $is_homepage && $in_count === 1 ) $issues[] = 'near_orphan';

    if ( $out_count === 0 ) $issues[] = 'no_outgoing_internal_links';

    // Low authority on important strategic pages.
    $stype = isset( $auth['strategic_type'] ) ? $auth['strategic_type'] : 'other';
    $score = isset( $auth['internal_authority_score'] ) ? $auth['internal_authority_score'] : 0;
    $important = array( 'money', 'service', 'location', 'product', 'category' );

    // Compute median once for the whole set — reuse from cluster_signals?
    // We don't have median here cheaply; use a static cache keyed by call.
    static $median_cache = null;
    if ( null === $median_cache ) {
        $vals = array();
        foreach ( $authority['per_page'] as $a ) $vals[] = $a['internal_authority_score'];
        sort( $vals );
        $median_cache = $vals ? $vals[ (int) ( count( $vals ) / 2 ) ] : 0;
    }
    if ( in_array( $stype, $important, true ) && $score <= $median_cache ) {
        $issues[] = 'low_authority_for_' . $stype . '_page';
    }

    // Generic-anchors-only inbound (signal of weak relevance).
    $generic = array( '', 'click here', 'here', 'read more', 'learn more', 'more', 'this', 'link', 'click', 'view', 'see more', 'continue', 'continue reading' );
    if ( isset( $rel['incoming_anchors'] ) && is_array( $rel['incoming_anchors'] ) && ! empty( $rel['incoming_anchors'] ) ) {
        $only_generic = true;
        foreach ( $rel['incoming_anchors'] as $a ) {
            $anchor = isset( $a['anchor'] ) ? strtolower( trim( $a['anchor'] ) ) : '';
            if ( ! in_array( $anchor, $generic, true ) ) { $only_generic = false; break; }
        }
        if ( $only_generic ) $issues[] = 'generic_anchors_only_inbound';
    }

    return $issues;
}
