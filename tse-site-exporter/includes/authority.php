<?php
/**
 * TSE Site Exporter — Weighted Internal Linking Engine (V2.3.0).
 *
 * Consumes the output of tse_relationships_build() and produces:
 *  - Strategic page classification (money/support/article/service/location/product/category/homepage/other).
 *  - Weighted edge graph (anchor quality, source authority, nofollow penalty).
 *  - Iterative PageRank-like internal authority score.
 *  - Composite per-page scores: relationship strength, contextual support,
 *    incoming link quality.
 *  - Cluster detection via union-find on the undirected link graph.
 *  - Intelligence flags: overlinked, under-supported important, isolated clusters,
 *    high outgoing / weak incoming.
 *
 * Backend-only, deterministic, scalable. No external services.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry. Returns:
 *  [
 *    'per_page'        => norm_url => slim per-page summary (for PageRecord injection),
 *    'authority_map'   => authority-map.json content,
 *    'weighted_graph'  => weighted-link-graph.json content,
 *    'strategic_pages' => strategic-pages.json content,
 *    'cluster_signals' => cluster-signals.json content,
 *    'intelligence'    => intelligence-flags.json content,
 *  ]
 */
function tse_authority_build( $records, $url_index, $relationships ) {
    // ---- URL → record index ----------------------------------------------
    $by_norm = array();
    foreach ( $records as $i => $r ) {
        $by_norm[ tse_normalize_url( $r['url'] ) ] = $i;
    }

    // ---- 1. Strategic classification --------------------------------------
    $strategic = array();
    foreach ( $records as $r ) {
        $norm = tse_normalize_url( $r['url'] );
        $per  = isset( $relationships['per_page'][ $norm ] ) ? $relationships['per_page'][ $norm ] : array();
        $strategic[ $norm ] = tse_authority_classify_strategic( $r, $per );
    }

    // ---- 2. Weighted edges ------------------------------------------------
    $weighted_edges = array();
    if ( isset( $relationships['graph']['edges'] ) ) {
        foreach ( $relationships['graph']['edges'] as $e ) {
            if ( ! empty( $e['is_self'] ) ) continue;
            $src_norm = tse_normalize_url( $e['source'] );
            $tgt_norm = tse_normalize_url( $e['target'] );
            $src_type = isset( $strategic[ $src_norm ]['type'] ) ? $strategic[ $src_norm ]['type'] : 'unknown';
            $tgt_type = isset( $strategic[ $tgt_norm ]['type'] ) ? $strategic[ $tgt_norm ]['type'] : 'unknown';
            $weighted_edges[] = array(
                'source'      => $e['source'],
                'source_norm' => $src_norm,
                'target'      => $e['target'],
                'target_norm' => $tgt_norm,
                'anchor'      => $e['anchor'],
                'rel'         => $e['rel'],
                'weight'      => tse_authority_edge_weight( $e, $src_type ),
                'source_type' => $src_type,
                'target_type' => $tgt_type,
            );
        }
    }

    // ---- 3. PageRank-like internal authority ------------------------------
    $authority_raw = tse_authority_compute_pagerank( array_keys( $by_norm ), $weighted_edges );

    $max_pr   = $authority_raw ? max( $authority_raw ) : 0;
    $authority = array();
    foreach ( $authority_raw as $norm => $val ) {
        $authority[ $norm ] = $max_pr > 0 ? round( ( $val / $max_pr ) * 100, 2 ) : 0;
    }

    // ---- 4. Composite scores ----------------------------------------------
    // Pre-index incoming edges per target.
    $incoming_by_norm = array();
    foreach ( $weighted_edges as $e ) {
        $incoming_by_norm[ $e['target_norm'] ][] = $e;
    }

    $rs_raw = array(); // relationship_strength_score
    $cs_raw = array(); // contextual_support_score
    $iq     = array(); // incoming_link_quality_score (already 0..100)

    foreach ( $records as $r ) {
        $norm = tse_normalize_url( $r['url'] );
        $per  = isset( $relationships['per_page'][ $norm ] )
            ? $relationships['per_page'][ $norm ]
            : array(
                'incoming_link_count'  => 0,
                'outgoing_link_count'  => 0,
                'unique_linking_pages' => 0,
                'unique_target_pages'  => 0,
                'incoming_anchors'     => array(),
            );

        $in_count    = (int) $per['incoming_link_count'];
        $unique_in   = (int) $per['unique_linking_pages'];
        $anchor_div  = is_array( $per['incoming_anchors'] ) ? count( $per['incoming_anchors'] ) : 0;

        $rs_raw[ $norm ] = $in_count * 0.4 + $unique_in * 0.4 + min( $anchor_div, 10 ) * 0.2;

        $in_edges = isset( $incoming_by_norm[ $norm ] ) ? $incoming_by_norm[ $norm ] : array();
        $same_type = 0;
        $my_type   = $strategic[ $norm ]['type'];
        foreach ( $in_edges as $e ) {
            if ( $e['source_type'] === $my_type ) $same_type++;
        }
        $cs_raw[ $norm ] = count( $in_edges ) > 0 ? ( $same_type / count( $in_edges ) ) : 0;

        $sum_w = 0; $sum_aw = 0;
        foreach ( $in_edges as $e ) {
            $sa     = isset( $authority[ $e['source_norm'] ] ) ? $authority[ $e['source_norm'] ] : 0;
            $sum_w  += $e['weight'];
            $sum_aw += $sa * $e['weight'];
        }
        $iq[ $norm ] = $sum_w > 0 ? round( $sum_aw / $sum_w, 2 ) : 0;
    }

    // Normalise rs and cs to 0..100.
    $rs_max = $rs_raw ? max( $rs_raw ) : 0;
    $cs_max = $cs_raw ? max( $cs_raw ) : 0;
    $rs = array();
    $cs = array();
    foreach ( $rs_raw as $k => $v ) $rs[ $k ] = $rs_max > 0 ? round( ( $v / $rs_max ) * 100, 2 ) : 0;
    foreach ( $cs_raw as $k => $v ) $cs[ $k ] = $cs_max > 0 ? round( ( $v / $cs_max ) * 100, 2 ) : 0;

    // ---- 5. Cluster detection (union-find on undirected graph) ------------
    $clusters = tse_authority_detect_clusters( array_keys( $by_norm ), $weighted_edges, $strategic );

    // ---- 6. Per-page summary (for PageRecord injection) -------------------
    $per_page = array();
    foreach ( $by_norm as $norm => $idx ) {
        $per_page[ $norm ] = array(
            'strategic_type'              => $strategic[ $norm ]['type'],
            'strategic_confidence'        => $strategic[ $norm ]['confidence'],
            'strategic_signals'           => $strategic[ $norm ]['signals'],
            'internal_authority_score'    => isset( $authority[ $norm ] ) ? $authority[ $norm ] : 0,
            'relationship_strength_score' => isset( $rs[ $norm ] )        ? $rs[ $norm ]        : 0,
            'contextual_support_score'    => isset( $cs[ $norm ] )        ? $cs[ $norm ]        : 0,
            'incoming_link_quality_score' => isset( $iq[ $norm ] )        ? $iq[ $norm ]        : 0,
        );
    }

    // ---- 7. Ranked table (authority-map.json + strategic-pages.json) -----
    $ranked = array();
    foreach ( $records as $r ) {
        $norm = tse_normalize_url( $r['url'] );
        $ranked[] = array_merge(
            array(
                'id'             => (int) $r['id'],
                'url'            => (string) $r['url'],
                'post_type'      => (string) $r['post_type'],
                'classification' => (string) $r['classification'],
            ),
            $per_page[ $norm ]
        );
    }
    usort( $ranked, function( $a, $b ) {
        if ( $a['internal_authority_score'] === $b['internal_authority_score'] ) return 0;
        return ( $a['internal_authority_score'] < $b['internal_authority_score'] ) ? 1 : -1;
    } );
    foreach ( $ranked as $i => &$row ) {
        $row['rank'] = $i + 1;
    }
    unset( $row );

    // ---- 8. Intelligence detection ----------------------------------------
    $incoming_counts = array();
    foreach ( $records as $r ) {
        $norm = tse_normalize_url( $r['url'] );
        $incoming_counts[] = isset( $relationships['per_page'][ $norm ]['incoming_link_count'] )
            ? (int) $relationships['per_page'][ $norm ]['incoming_link_count']
            : 0;
    }
    $p95 = tse_authority_percentile( $incoming_counts, 95 );

    $auth_vals = array_values( $authority );
    sort( $auth_vals );
    $median = $auth_vals ? $auth_vals[ (int) ( count( $auth_vals ) / 2 ) ] : 0;
    $important_types = array( 'money', 'service', 'location', 'product', 'category' );

    $overlinked       = array();
    $under_supported  = array();
    $high_out_weak_in = array();

    foreach ( $records as $r ) {
        $norm        = tse_normalize_url( $r['url'] );
        $per         = isset( $relationships['per_page'][ $norm ] ) ? $relationships['per_page'][ $norm ] : array();
        $in_count    = isset( $per['incoming_link_count'] ) ? (int) $per['incoming_link_count'] : 0;
        $out_count   = isset( $per['outgoing_link_count'] ) ? (int) $per['outgoing_link_count'] : 0;
        $a           = isset( $authority[ $norm ] ) ? $authority[ $norm ] : 0;
        $type        = $strategic[ $norm ]['type'];

        $entry = array(
            'id'                       => (int) $r['id'],
            'url'                      => (string) $r['url'],
            'strategic_type'           => $type,
            'incoming_link_count'      => $in_count,
            'outgoing_link_count'      => $out_count,
            'internal_authority_score' => $a,
        );

        if ( $p95 > 0 && $in_count >= $p95 && $in_count >= 10 ) {
            $entry['threshold_p95'] = $p95;
            $overlinked[] = $entry;
        }
        if ( in_array( $type, $important_types, true ) && 'conversion' !== $type && $a < $median ) {
            $entry['median_authority'] = $median;
            $under_supported[] = $entry;
        }
        if ( $out_count >= 10 && $in_count <= 1 ) {
            $high_out_weak_in[] = $entry;
        }
    }

    // ---- 9. Dataset files --------------------------------------------------
    $authority_map = array(
        'description' => 'Per-page internal authority and composite relationship scores. All composite scores normalised to 0..100.',
        'scoring' => array(
            'internal_authority_score'    => 'PageRank-like, weighted edges, damping 0.85, 30 iterations, normalised to 0..100.',
            'relationship_strength_score' => 'Weighted blend: incoming_link_count (0.4) + unique_linking_pages (0.4) + anchor diversity capped at 10 (0.2), normalised.',
            'contextual_support_score'    => 'Share of incoming links coming from pages of the same strategic type.',
            'incoming_link_quality_score' => 'Weighted average internal_authority_score of source pages (weighted by edge weight).',
        ),
        'count' => count( $ranked ),
        'pages' => $ranked,
    );

    $slim_weighted = array();
    foreach ( $weighted_edges as $e ) {
        $slim_weighted[] = array(
            'source'      => $e['source'],
            'target'      => $e['target'],
            'anchor'      => $e['anchor'],
            'rel'         => $e['rel'],
            'weight'      => $e['weight'],
            'source_type' => $e['source_type'],
            'target_type' => $e['target_type'],
        );
    }
    $weighted_graph = array(
        'description' => 'Internal-link edges with computed authority-propagation weights.',
        'weighting'   => array(
            'base'                 => 1.0,
            'descriptive_anchor'   => '+0.5 (anchor has >= 2 words and is not generic)',
            'high_value_source'    => '+0.3 (source strategic_type is homepage or money)',
            'generic_anchor'       => '-0.3 (e.g. "click here", "read more")',
            'nofollow'             => 'x0.2 (rel=nofollow)',
            'floor'                => 0.05,
        ),
        'totals' => array(
            'edges' => count( $slim_weighted ),
        ),
        'edges'  => $slim_weighted,
    );

    $strategic_pages = array(
        'description' => 'Lightweight strategic page classification using URL patterns, post-type, schema, content signals and CRO heuristics.',
        'taxonomy'    => array(
            'money'    => 'Conversion-focused (pricing, contact, quote, booking, checkout, demo, signup).',
            'support'  => 'Help, FAQ, documentation, knowledge base.',
            'article'  => 'Blog posts, news, articles.',
            'service'  => 'Service offering pages.',
            'location' => 'Location / area-served pages.',
        'conversion' => 'Conversion endpoints (contact / quote / checkout) — CTA destinations, not SEO authority targets.',
            'product'  => 'WooCommerce or similar product pages.',
            'category' => 'Product / post categories / archive pages.',
            'homepage' => 'Site front page.',
            'other'    => 'Unclassified.',
        ),
        'count' => count( $ranked ),
        'pages' => $ranked,
    );

    $cluster_signals = array(
        'description' => 'Weakly-connected components of the internal-link graph. Pages outside the homepage component are flagged as isolated.',
        'totals'      => array(
            'clusters'          => count( $clusters['clusters'] ),
            'isolated_clusters' => $clusters['isolated_count'],
            'main_cluster_size' => $clusters['main_size'],
        ),
        'main_cluster_id' => $clusters['main_id'],
        'clusters'        => $clusters['clusters'],
    );

    $intelligence = array(
        'description' => 'Relationship intelligence flags derived from authority + relationship metrics.',
        'thresholds'  => array(
            'overlinked_p95_incoming' => $p95,
            'authority_median'        => $median,
            'important_types'         => $important_types,
            'high_out_weak_in_rule'   => 'outgoing_link_count >= 10 AND incoming_link_count <= 1',
        ),
        'overlinked_pages'                    => $overlinked,
        'under_supported_important_pages'     => $under_supported,
        'high_outgoing_weak_incoming_pages'   => $high_out_weak_in,
    );

    return array(
        'per_page'        => $per_page,
        'authority_map'   => $authority_map,
        'weighted_graph'  => $weighted_graph,
        'strategic_pages' => $strategic_pages,
        'cluster_signals' => $cluster_signals,
        'intelligence'    => $intelligence,
    );
}

/* -------------------------------------------------------------------------
 * Strategic classifier
 * ---------------------------------------------------------------------- */
function tse_authority_classify_strategic( $r, $per_page_rel ) {
    $url            = strtolower( (string) $r['url'] );
    $parts          = wp_parse_url( $url );
    $path           = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) . '/' : '/';
    $classification = isset( $r['classification'] ) ? $r['classification'] : '';
    $post_type      = isset( $r['post_type'] )      ? $r['post_type']      : 'page';
    $signals        = array();

    // V2.10.1 — Hard override: URL is declared as a geo/location target.
    // Run as the very first check so the user's explicit declaration always
    // beats heuristics.
    if ( function_exists( 'tse_authority_strategy_location_lookup' ) ) {
        $geo_set = tse_authority_strategy_location_lookup();
        $norm    = tse_authority_normalise_path( $url );
        if ( '' !== $norm && isset( $geo_set[ $norm ] ) ) {
            return array(
                'type'       => 'location',
                'confidence' => 1.0,
                'signals'    => array( 'strategy:declared_geo_location_target' ),
            );
        }
    }

    // V2.10.2 — Hard override: URL is declared as a primary_conversion_page.
    // Conversion endpoints (contact, quote, checkout) MUST NOT be classified
    // as SEO authority targets — they are CTA destinations, not pages we try
    // to rank.
    if ( function_exists( 'tse_authority_strategy_conversion_lookup' ) ) {
        $conv_set = tse_authority_strategy_conversion_lookup();
        $norm     = tse_authority_normalise_path( $url );
        if ( '' !== $norm && isset( $conv_set[ $norm ] ) ) {
            return array(
                'type'       => 'conversion',
                'confidence' => 1.0,
                'signals'    => array( 'strategy:declared_primary_conversion' ),
            );
        }
    }

    // Homepage wins first.
    if ( 'homepage' === $classification ) {
        return array( 'type' => 'homepage', 'confidence' => 1.0, 'signals' => array( 'wp_front_page' ) );
    }

    // Post-type signals (strong).
    if ( in_array( $post_type, array( 'product', 'product_variation' ), true ) ) {
        return array( 'type' => 'product', 'confidence' => 1.0, 'signals' => array( 'post_type:' . $post_type ) );
    }
    if ( in_array( $post_type, array( 'product_cat', 'product_tag', 'category' ), true ) ) {
        return array( 'type' => 'category', 'confidence' => 1.0, 'signals' => array( 'post_type:' . $post_type ) );
    }

    $type = 'other';
    $conf = 0.4;
    if ( 'post' === $post_type ) {
        $signals[] = 'post_type:post';
        $type      = 'article';
        $conf      = 0.8;
    }

    // URL pattern signals.
    // V2.10.2 — CTA / checkout endpoints split out of 'money' into 'conversion'
    // so they are no longer treated as SEO authority targets. The few remaining
    // 'money' patterns are commercial-INTENT pages (pricing / buy), which we
    // DO still rank against.
    $patterns = array(
        'conversion' => array( '/contact/', '/contact-us/', '/get-in-touch/', '/quote/', '/get-a-quote/', '/request-a-quote/', '/free-quote/', '/free-trial/', '/book/', '/booking/', '/book-a-call/', '/checkout/', '/cart/', '/get-started/', '/signup/', '/sign-up/', '/register/', '/demo/', '/request-demo/', '/schedule/' ),
        'money'      => array( '/pricing/', '/price/', '/buy/', '/order/' ),
        'support'    => array( '/help/', '/faq/', '/faqs/', '/support/', '/docs/', '/documentation/', '/knowledge-base/', '/kb/', '/guides/' ),
        'article'    => array( '/blog/', '/news/', '/articles/', '/insights/' ),
        'service'    => array( '/services/', '/service/', '/what-we-do/' ),
        'location'   => array( '/locations/', '/location/', '/areas-served/', '/areas-we-serve/', '/service-area/', '/service-areas/', '/cities/', '/near-me/' ),
        'category'   => array( '/category/', '/categories/', '/tag/', '/archive/' ),
        'product'    => array( '/product/', '/products/', '/shop/' ),
    );
    foreach ( $patterns as $t => $needles ) {
        foreach ( $needles as $needle ) {
            if ( false !== strpos( $path, $needle ) ) {
                $signals[] = 'url:' . trim( $needle, '/' );
                $type      = $t;
                $conf      = max( $conf, 0.85 );
                break 2;
            }
        }
    }

    // V2.10.1 — Location heuristics. Can upgrade type='location' over
    // service/article/other when the URL or H1/title carries a clear geo
    // modifier. Homepage / product / category remain immune (returned early).
    if ( in_array( $type, array( 'other', 'service', 'article', 'support' ), true ) ) {
        $geo_signal = tse_authority_detect_geo_signal( $url, $r );
        if ( null !== $geo_signal ) {
            $signals[] = $geo_signal;
            $type      = 'location';
            $conf      = max( $conf, 0.85 );
        }
    }

    // CRO signals → reinforce or upgrade to money (BUT not from 'conversion').
    if ( ! empty( $r['cro'] ) && is_array( $r['cro'] ) ) {
        $cro         = $r['cro'];
        $cta_count   = isset( $cro['cta_count'] )   ? (int) $cro['cta_count']   : ( isset( $cro['ctas'] )   ? count( (array) $cro['ctas'] )   : 0 );
        $form_count  = isset( $cro['form_count'] )  ? (int) $cro['form_count']  : ( isset( $cro['forms'] )  ? count( (array) $cro['forms'] )  : 0 );
        $phone_count = isset( $cro['phone_count'] ) ? (int) $cro['phone_count'] : ( isset( $cro['phones'] ) ? count( (array) $cro['phones'] ) : 0 );
        $strong      = ( $cta_count + $form_count + $phone_count ) >= 3;
        if ( $strong ) {
            $signals[] = 'cro:strong';
            if ( 'other' === $type ) {
                $type = 'money';
                $conf = 0.7;
            } elseif ( 'conversion' === $type ) {
                // V2.10.2 — keep type='conversion'; just boost confidence.
                $conf = min( 1.0, $conf + 0.05 );
            } else {
                $conf = min( 1.0, $conf + 0.05 );
            }
        }
    }

    // Schema signals.
    $schema_types = array();
    if ( ! empty( $r['schema']['types'] ) && is_array( $r['schema']['types'] ) ) {
        $schema_types = $r['schema']['types'];
    }
    foreach ( $schema_types as $st ) {
        $stl = strtolower( (string) $st );
        if ( false !== strpos( $stl, 'localbusiness' ) || 'place' === $stl || 'geocoordinates' === $stl ) {
            $signals[] = 'schema:LocalBusiness';
            // V2.10.1 — Local schema can upgrade service/article/support → location.
            if ( in_array( $type, array( 'other', 'service', 'article', 'support' ), true ) ) {
                $type = 'location'; $conf = max( $conf, 0.85 );
            }
        }
        if ( 'product' === $stl && 'other' === $type ) {
            $type = 'product'; $conf = 0.9; $signals[] = 'schema:Product';
        }
        if ( in_array( $stl, array( 'article', 'blogposting', 'newsarticle' ), true ) ) {
            $signals[] = 'schema:' . $st;
            if ( 'other' === $type ) { $type = 'article'; $conf = 0.75; }
        }
        if ( 'faqpage' === $stl ) {
            $signals[] = 'schema:FAQPage';
            if ( 'other' === $type ) { $type = 'support'; $conf = 0.85; }
        }
        if ( 'service' === $stl && 'other' === $type ) {
            $type = 'service'; $conf = 0.85; $signals[] = 'schema:Service';
        }
    }

    // FAQ content block signal.
    if ( ! empty( $r['content']['faq'] ) && is_array( $r['content']['faq'] ) && count( $r['content']['faq'] ) > 0 ) {
        $signals[] = 'content:has_faq';
        if ( 'other' === $type ) { $type = 'support'; $conf = 0.6; }
    }

    return array(
        'type'       => $type,
        'confidence' => round( $conf, 2 ),
        'signals'    => array_values( array_unique( $signals ) ),
    );
}

/* -------------------------------------------------------------------------
 * Edge weighting
 * ---------------------------------------------------------------------- */
function tse_authority_edge_weight( $edge, $source_type ) {
    $w      = 1.0;
    $anchor = strtolower( trim( (string) ( isset( $edge['anchor'] ) ? $edge['anchor'] : '' ) ) );
    $generic = array( '', 'click here', 'here', 'read more', 'learn more', 'more', 'this', 'link', 'click', 'view', 'see more', 'continue', 'continue reading' );

    if ( in_array( $anchor, $generic, true ) ) {
        $w -= 0.3;
    } elseif ( str_word_count( $anchor ) >= 2 ) {
        $w += 0.5;
    }
    if ( in_array( $source_type, array( 'homepage', 'money' ), true ) ) {
        $w += 0.3;
    }
    $rel = isset( $edge['rel'] ) ? (array) $edge['rel'] : array();
    $rel = array_map( 'strtolower', $rel );
    if ( in_array( 'nofollow', $rel, true ) ) {
        $w *= 0.2;
    }
    return round( max( 0.05, $w ), 3 );
}

/* -------------------------------------------------------------------------
 * PageRank-like authority (weighted, iterative)
 * ---------------------------------------------------------------------- */
function tse_authority_compute_pagerank( $nodes, $edges, $damping = 0.85, $iters = 30 ) {
    $n = count( $nodes );
    if ( 0 === $n ) return array();
    $pr = array_fill_keys( $nodes, 1.0 / $n );

    $out     = array();
    $out_sum = array();
    foreach ( $edges as $e ) {
        $s = $e['source_norm'];
        if ( ! isset( $pr[ $s ] ) ) continue;
        $t = $e['target_norm'];
        if ( ! isset( $pr[ $t ] ) ) continue;
        $out[ $s ][]   = array( 'target' => $t, 'weight' => $e['weight'] );
        $out_sum[ $s ] = ( isset( $out_sum[ $s ] ) ? $out_sum[ $s ] : 0 ) + $e['weight'];
    }

    $base = ( 1 - $damping ) / $n;
    for ( $i = 0; $i < $iters; $i++ ) {
        $new = array_fill_keys( $nodes, $base );
        foreach ( $nodes as $u ) {
            if ( empty( $out[ $u ] ) || empty( $out_sum[ $u ] ) ) {
                // Dangling node: do NOT redistribute. Isolated pages should
                // not inherit authority from the rest of the graph.
                continue;
            }
            $share = $damping * $pr[ $u ] / $out_sum[ $u ];
            foreach ( $out[ $u ] as $edge ) {
                $new[ $edge['target'] ] += $share * $edge['weight'];
            }
        }
        $pr = $new;
    }
    return $pr;
}

/* -------------------------------------------------------------------------
 * Cluster detection (union-find on undirected graph)
 * ---------------------------------------------------------------------- */
function tse_authority_detect_clusters( $nodes, $edges, $strategic ) {
    $parent = array();
    foreach ( $nodes as $n ) $parent[ $n ] = $n;

    $find = function( $x ) use ( &$parent ) {
        while ( $parent[ $x ] !== $x ) {
            $parent[ $x ] = $parent[ $parent[ $x ] ];
            $x = $parent[ $x ];
        }
        return $x;
    };

    foreach ( $edges as $e ) {
        if ( ! isset( $parent[ $e['source_norm'] ] ) || ! isset( $parent[ $e['target_norm'] ] ) ) continue;
        $ra = $find( $e['source_norm'] );
        $rb = $find( $e['target_norm'] );
        if ( $ra !== $rb ) $parent[ $ra ] = $rb;
    }

    $groups = array();
    foreach ( $nodes as $n ) {
        $root            = $find( $n );
        $groups[ $root ][] = $n;
    }

    $homepage_root = null;
    foreach ( $nodes as $n ) {
        if ( isset( $strategic[ $n ]['type'] ) && 'homepage' === $strategic[ $n ]['type'] ) {
            $homepage_root = $find( $n );
            break;
        }
    }

    $clusters       = array();
    $isolated_count = 0;
    $main_size      = 0;
    $main_id        = null;
    $cid            = 0;
    foreach ( $groups as $root => $members ) {
        $cid++;
        $is_main        = ( null !== $homepage_root && $root === $homepage_root );
        $type_breakdown = array();
        foreach ( $members as $m ) {
            $t = isset( $strategic[ $m ]['type'] ) ? $strategic[ $m ]['type'] : 'other';
            $type_breakdown[ $t ] = isset( $type_breakdown[ $t ] ) ? $type_breakdown[ $t ] + 1 : 1;
        }
        arsort( $type_breakdown );
        $keys           = array_keys( $type_breakdown );
        $dominant_type  = ! empty( $keys ) ? $keys[0] : 'other';

        $cluster = array(
            'cluster_id'     => $cid,
            'size'           => count( $members ),
            'is_main'        => $is_main,
            'is_isolated'    => ! $is_main,
            'dominant_type'  => $dominant_type,
            'type_breakdown' => $type_breakdown,
            'members'        => array_values( $members ),
        );
        if ( $is_main ) {
            $main_size = count( $members );
            $main_id   = $cid;
        } else {
            $isolated_count++;
        }
        $clusters[] = $cluster;
    }

    usort( $clusters, function( $a, $b ) {
        if ( $a['is_main'] !== $b['is_main'] ) return $b['is_main'] - $a['is_main'];
        return $b['size'] - $a['size'];
    } );

    return array(
        'clusters'       => $clusters,
        'isolated_count' => $isolated_count,
        'main_size'      => $main_size,
        'main_id'        => $main_id,
    );
}

/* -------------------------------------------------------------------------
 * Percentile helper
 * ---------------------------------------------------------------------- */
function tse_authority_percentile( $values, $p ) {
    if ( ! $values ) return 0;
    sort( $values );
    $idx = (int) ceil( ( $p / 100 ) * count( $values ) ) - 1;
    if ( $idx < 0 ) $idx = 0;
    if ( $idx >= count( $values ) ) $idx = count( $values ) - 1;
    return $values[ $idx ];
}


/* -------------------------------------------------------------------------
 * V2.10.1 — Geo / location helpers
 * ---------------------------------------------------------------------- */

/**
 * Lookup of geo/location target normalised paths declared by the user in
 * the Strategic SEO Configuration. Cached per-request so we don't hit
 * wp_options on every PageRecord. Keys are normalised paths (lowercase,
 * trailing slash).
 */
function tse_authority_strategy_location_lookup() {
    static $cache = null;
    if ( null !== $cache ) return $cache;
    $cache = array();
    if ( ! function_exists( 'tse_strategy_get' ) ) return $cache;
    $st = tse_strategy_get();
    $list = isset( $st['geo_location_targets'] ) ? (array) $st['geo_location_targets'] : array();
    foreach ( $list as $entry ) {
        $n = function_exists( 'tse_strategy_normalise_url' )
            ? tse_strategy_normalise_url( $entry )
            : tse_authority_normalise_path( $entry );
        if ( '' !== $n ) $cache[ $n ] = true;
    }
    return $cache;
}

/**
 * V2.10.2 — Set of normalised paths declared by the user as Primary Conversion
 * Pages. Used to hard-classify them as strategic_type='conversion' and to
 * suppress authority-building recommendations against them.
 */
function tse_authority_strategy_conversion_lookup() {
    static $cache = null;
    if ( null !== $cache ) return $cache;
    $cache = array();
    if ( ! function_exists( 'tse_strategy_get' ) ) return $cache;
    $st = tse_strategy_get();
    $list = isset( $st['primary_conversion_pages'] ) ? (array) $st['primary_conversion_pages'] : array();
    foreach ( $list as $entry ) {
        $n = function_exists( 'tse_strategy_normalise_url' )
            ? tse_strategy_normalise_url( $entry )
            : tse_authority_normalise_path( $entry );
        if ( '' !== $n ) $cache[ $n ] = true;
    }
    return $cache;
}

function tse_authority_normalise_path( $url ) {
    $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
    $path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
    if ( '' === $path ) $path = '/';
    if ( '/' !== $path[0] ) $path = '/' . $path;
    $path = preg_replace( '/[#?].*$/', '', $path );
    $path = strtolower( $path );
    if ( '/' !== substr( $path, -1 ) ) $path .= '/';
    return $path;
}

/**
 * Detect a strong geo modifier on a record using:
 *   1. URL geo modifiers (e.g. "-in-leeds", "-leeds-", "/leeds/", "-near-me")
 *   2. H1 / title pattern "[Service] in [Capitalised Place]"
 *   3. Known UK / common locality tokens in the slug
 *
 * Returns a short signal string ("url:in-place", "h1:in-place", "slug:locality")
 * or null when nothing matches.
 */
function tse_authority_detect_geo_signal( $url, $r ) {
    $path = '';
    $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
    if ( is_array( $parts ) && isset( $parts['path'] ) ) $path = $parts['path'];

    // ----- 1. URL-segment patterns.
    if ( preg_match( '#(?:^|/|-)in-([a-z][a-z\-]{2,30})(?:/|-|$)#', $path, $m ) && ! tse_authority_is_filler_word( $m[1] ) ) {
        return 'url:in-' . $m[1];
    }
    if ( preg_match( '#-near-me(?:/|$)#', $path ) ) {
        return 'url:near-me';
    }
    if ( preg_match( '#/(' . tse_authority_uk_geo_regex() . ')-#', $path, $m ) ) {
        return 'url:city-prefix:' . $m[1];
    }
    if ( preg_match( '#-(' . tse_authority_uk_geo_regex() . ')(?:/|$)#', $path, $m ) ) {
        return 'url:city-suffix:' . $m[1];
    }
    if ( preg_match( '#/(' . tse_authority_uk_geo_regex() . ')/#', $path, $m ) ) {
        return 'url:city-segment:' . $m[1];
    }

    // ----- 2. H1 / title "in <Place>" pattern.
    $haystacks = array();
    if ( ! empty( $r['content']['h1'] ) ) {
        foreach ( (array) $r['content']['h1'] as $h1 ) $haystacks[] = (string) $h1;
    }
    $title = isset( $r['seo']['meta_title'] ) ? (string) $r['seo']['meta_title'] : '';
    if ( '' !== $title ) $haystacks[] = $title;

    foreach ( $haystacks as $hs ) {
        // "in Leeds", "in Leeds City Centre", "in Greater Manchester".
        if ( preg_match( '/\bin\s+([A-Z][a-zA-Z]+(?:[\s-][A-Z][a-zA-Z]+){0,2})\b/u', $hs, $m ) ) {
            $candidate = strtolower( $m[1] );
            if ( ! tse_authority_is_filler_word( $candidate ) ) {
                return 'h1:in-' . str_replace( ' ', '-', $candidate );
            }
        }
        // "<Service> Leeds" — last capitalised token matches a known city.
        if ( preg_match( '/\b(' . tse_authority_uk_geo_regex_capitalised() . ')\b/u', $hs, $m ) ) {
            return 'h1:city:' . strtolower( $m[1] );
        }
    }

    return null;
}

function tse_authority_is_filler_word( $w ) {
    $w = strtolower( trim( (string) $w ) );
    if ( '' === $w ) return true;
    // Multi-word capture: bail if the FIRST token is a filler (e.g. "the-area").
    $first = preg_split( '/[\s\-]+/', $w )[0] ?? $w;
    static $fillers = array(
        'the', 'and', 'for', 'with', 'your', 'our', 'this', 'that',
        'general', 'simple', 'easy', 'detail', 'depth', 'time', 'mind',
        'order', 'place', 'turn', 'fact', 'short', 'addition', 'house',
        'home', 'office', 'business', 'shop', 'store', 'area', 'areas',
        'minute', 'minutes', 'second', 'seconds', 'hour', 'hours',
    );
    return in_array( $first, $fillers, true ) || in_array( $w, $fillers, true );
}

/**
 * Pragmatic UK / common-English city dictionary used for slug/H1 detection.
 * Kept conservative — adding too many words causes false positives on
 * generic copy. Users who care should declare URLs in geo_location_targets.
 */
function tse_authority_uk_geo_tokens() {
    static $list = null;
    if ( null !== $list ) return $list;
    $list = array(
        // Big UK cities + regions
        'london','birmingham','leeds','glasgow','sheffield','bradford','manchester',
        'liverpool','bristol','edinburgh','cardiff','belfast','newcastle','nottingham',
        'leicester','sunderland','southampton','portsmouth','york','brighton','hull',
        'plymouth','stoke','wolverhampton','coventry','reading','swansea','aberdeen',
        'derby','dundee','milton-keynes','northampton','luton','oxford','cambridge',
        'norwich','exeter','bath','peterborough','bournemouth','blackpool','preston',
        'middlesbrough','huddersfield','blackburn','warrington','oldham','bolton',
        'rotherham','stockport','watford','swindon','telford','ipswich',
        // Greater regions
        'yorkshire','lancashire','merseyside','cheshire','kent','surrey','essex',
        'sussex','hampshire','hertfordshire','wiltshire','dorset','devon','cornwall',
        'midlands','tyneside','wearside',
    );
    return $list;
}

function tse_authority_uk_geo_regex() {
    static $rx = null;
    if ( null !== $rx ) return $rx;
    $rx = implode( '|', array_map( 'preg_quote', tse_authority_uk_geo_tokens() ) );
    return $rx;
}

function tse_authority_uk_geo_regex_capitalised() {
    static $rx = null;
    if ( null !== $rx ) return $rx;
    $tokens = tse_authority_uk_geo_tokens();
    $caps = array_map( function( $t ) {
        return implode( '-', array_map( 'ucfirst', explode( '-', $t ) ) );
    }, $tokens );
    $rx = implode( '|', array_map( 'preg_quote', $caps ) );
    return $rx;
}
