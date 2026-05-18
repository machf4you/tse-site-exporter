<?php
/**
 * TSE Site Exporter — Strategic SEO Configuration Layer (V2.9.0).
 *
 * Optional human-declared business strategy that lets the recommendation
 * engine compare **declared intent** vs **actual internal architecture**.
 *
 * Six buckets are supported:
 *   - money_pages
 *   - support_pages
 *   - location_pages
 *   - priority_urls
 *   - primary_conversion_pages
 *   - protected_urls
 *
 * Each bucket is a newline-delimited list. Entries can be:
 *   - a full URL                 (https://example.test/foo/)
 *   - a path                     (/foo/)
 *   - a path without trailing /  (foo)
 *
 * Persistence: `wp_options` row `tse_site_exporter_strategy`.
 * Output to export bundle:
 *   - strategy-config.json     (the saved declaration)
 *   - strategy-mismatch.json   (deterministic declared-vs-actual findings)
 *
 * The recommendation engine treats this file like any other deterministic
 * AI-summary input. Reports render a dedicated "Strategy vs reality" block.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TSE_STRATEGY_OPTION', 'tse_site_exporter_strategy' );
define( 'TSE_STRATEGY_NONCE',  'tse_site_exporter_strategy' );

/**
 * The 6 supported buckets (V2.10.1 — simplified, user-confirmed).
 * Order matters: it is the UI render order AND the mismatch reporting order.
 *
 * V2.10 introduced 9 buckets; we explicitly dropped:
 *   - current_seo_targets
 *   - growth_targets
 *   - campaign_pages
 * Any user data still sitting in those keys is auto-merged into
 * `active_strategic_targets` by tse_strategy_migrate_legacy().
 */
function tse_strategy_buckets() {
    return array(
        'active_strategic_targets' => array(
            'label'       => __( 'Active Strategic Targets', 'tse-site-exporter' ),
            'help'        => __( 'Pages you are actively prioritising right now. Edit this list as priorities shift — these are not permanent labels.', 'tse-site-exporter' ),
            'placeholder' => "/bathroom-renovations/\n/kitchen-fitting/",
        ),
        'geo_location_targets'     => array(
            'label'       => __( 'Geo / Location Targets', 'tse-site-exporter' ),
            'help'        => __( 'Local-SEO pages targeting a specific town / region. Listed URLs are force-classified as location pages.', 'tse-site-exporter' ),
            'placeholder' => "/bathroom-renovations-leeds/",
        ),
        'support_pages'            => array(
            'label'       => __( 'Support Pages', 'tse-site-exporter' ),
            'help'        => __( 'Topical articles, FAQs, guides that feed the strategic targets above.', 'tse-site-exporter' ),
            'placeholder' => "/how-long-does-a-bathroom-renovation-take/",
        ),
        'priority_urls'            => array(
            'label'       => __( 'Priority URLs', 'tse-site-exporter' ),
            'help'        => __( 'Any URL you want internal-link analysis to weight extra.', 'tse-site-exporter' ),
            'placeholder' => "/our-process/",
        ),
        'primary_conversion_pages' => array(
            'label'       => __( 'Primary Conversion Pages', 'tse-site-exporter' ),
            'help'        => __( 'The final-step URLs that capture leads / sales (quote form, checkout).', 'tse-site-exporter' ),
            'placeholder' => "/get-a-quote/",
        ),
        'protected_urls'           => array(
            'label'       => __( 'Protected URLs', 'tse-site-exporter' ),
            'help'        => __( 'URLs the engine should never suggest changing, merging, redirecting or noindexing.', 'tse-site-exporter' ),
            'placeholder' => "/legacy-campaign-do-not-touch/",
        ),
    );
}

/**
 * Migrate legacy bucket keys (V2.9 → V2.10 → V2.10.1) silently on first read.
 *   money_pages         → active_strategic_targets    (V2.9 → V2.10)
 *   location_pages      → geo_location_targets         (V2.9 → V2.10)
 *   current_seo_targets → active_strategic_targets    (V2.10 → V2.10.1)
 *   growth_targets      → active_strategic_targets    (V2.10 → V2.10.1)
 *   campaign_pages      → active_strategic_targets    (V2.10 → V2.10.1)
 */
function tse_strategy_migrate_legacy( $stored ) {
    if ( ! is_array( $stored ) ) return array();
    $map = array(
        'money_pages'         => 'active_strategic_targets',
        'location_pages'      => 'geo_location_targets',
        'current_seo_targets' => 'active_strategic_targets',
        'growth_targets'      => 'active_strategic_targets',
        'campaign_pages'      => 'active_strategic_targets',
    );
    $changed = false;
    foreach ( $map as $old => $new ) {
        if ( ! isset( $stored[ $old ] ) ) continue;
        if ( empty( $stored[ $old ] ) ) {
            unset( $stored[ $old ] );
            $changed = true;
            continue;
        }
        if ( empty( $stored[ $new ] ) ) {
            $stored[ $new ] = $stored[ $old ];
        } else {
            // Merge while preserving order + dedupe on normalised compare-keys.
            $merged = $stored[ $new ];
            $seen   = array();
            foreach ( $merged as $m ) $seen[ tse_strategy_normalise_url( $m ) ] = true;
            foreach ( $stored[ $old ] as $m ) {
                $k = tse_strategy_normalise_url( $m );
                if ( '' === $k || isset( $seen[ $k ] ) ) continue;
                $merged[] = $m;
                $seen[ $k ] = true;
            }
            $stored[ $new ] = $merged;
        }
        unset( $stored[ $old ] );
        $changed = true;
    }
    if ( $changed ) update_option( TSE_STRATEGY_OPTION, $stored, false );
    return $stored;
}

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

function tse_strategy_get() {
    $stored = get_option( TSE_STRATEGY_OPTION, array() );
    if ( ! is_array( $stored ) ) $stored = array();
    // V2.10 — silent migration of legacy bucket keys.
    $stored = tse_strategy_migrate_legacy( $stored );
    $out = array();
    foreach ( tse_strategy_buckets() as $key => $_meta ) {
        $out[ $key ] = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? array_values( $stored[ $key ] ) : array();
    }
    return $out;
}

function tse_strategy_save( $posted ) {
    $clean = array();
    foreach ( tse_strategy_buckets() as $key => $_meta ) {
        $raw  = isset( $posted[ $key ] ) ? (string) $posted[ $key ] : '';
        $list = tse_strategy_parse_textarea( $raw );
        $clean[ $key ] = $list;
    }
    update_option( TSE_STRATEGY_OPTION, $clean, false );
    return $clean;
}

/**
 * Parse a newline-delimited textarea: trim, drop blanks/comments, dedupe.
 * URLs are kept as-typed for display, but matching is done on the normalised
 * form (tse_strategy_normalise_url).
 */
function tse_strategy_parse_textarea( $raw ) {
    $lines = preg_split( "/\r?\n/", (string) $raw );
    $out   = array();
    $seen  = array();
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( '' === $line ) continue;
        if ( '#' === $line[0] ) continue; // comment
        // Drop weird control chars and excess whitespace.
        $line = preg_replace( '/\s+/', ' ', $line );
        if ( '' === $line ) continue;
        $norm = tse_strategy_normalise_url( $line );
        if ( '' === $norm || isset( $seen[ $norm ] ) ) continue;
        $seen[ $norm ] = true;
        $out[] = $line;
    }
    return $out;
}

/**
 * Normalise a URL OR a path to a canonical compare-key (path, lowercase,
 * trailing slash). Used ONLY for matching, not for display.
 *
 *   "https://example.test/Foo/Bar"  → "/foo/bar/"
 *   "/foo/bar"                       → "/foo/bar/"
 *   "foo/bar/"                       → "/foo/bar/"
 *   "https://example.test/"          → "/"
 */
function tse_strategy_normalise_url( $url ) {
    $url = trim( (string) $url );
    if ( '' === $url ) return '';

    $parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : parse_url( $url );
    $path  = '';
    if ( is_array( $parts ) ) {
        $path = isset( $parts['path'] ) ? $parts['path'] : '';
        if ( '' === $path && ! isset( $parts['host'] ) ) {
            // Bare relative input like "foo/bar".
            $path = $url;
        }
    } else {
        $path = $url;
    }
    if ( '' === $path ) $path = '/';
    if ( '/' !== $path[0] ) $path = '/' . $path;
    // Strip query / fragment defensively.
    $path = preg_replace( '/[#?].*$/', '', $path );
    // Lowercase + ensure trailing slash.
    $path = strtolower( $path );
    if ( '/' !== substr( $path, -1 ) ) $path .= '/';
    return $path;
}

/**
 * Returns an index { normalised_url => [ bucket1, bucket2, ... ] } for fast
 * lookup against the runtime page records.
 */
function tse_strategy_build_index( $strategy ) {
    $idx = array();
    foreach ( $strategy as $bucket => $list ) {
        foreach ( $list as $entry ) {
            $n = tse_strategy_normalise_url( $entry );
            if ( '' === $n ) continue;
            if ( ! isset( $idx[ $n ] ) ) $idx[ $n ] = array();
            if ( ! in_array( $bucket, $idx[ $n ], true ) ) $idx[ $n ][] = $bucket;
        }
    }
    return $idx;
}

/* -------------------------------------------------------------------------
 * Deterministic declared-vs-actual mismatch detection
 * ----------------------------------------------------------------------
 * Runs after authority + relationships are built so we can compare:
 *   - declared money page vs actual incoming_link_count + authority
 *   - declared priority URL vs actual incoming_link_count
 *   - declared primary conversion vs has-any-inbound-from-money
 *   - declared support but heuristic says money (role conflict)
 *   - declared location but heuristic says non-location (role conflict)
 *   - declared protected URL appears in duplicate-meta-title / desc sets
 *
 * Each finding mirrors the AI item schema so it flows through reports.
 */
function tse_strategy_build_mismatch( $strategy, $records, $relationships, $authority, $extras = array() ) {
    $items = array();

    // Quick lookups.
    $idx = tse_strategy_build_index( $strategy );

    // URL -> record snapshot indexed by normalised URL.
    $snap = array();
    foreach ( $records as $r ) {
        $u = isset( $r['url'] ) ? (string) $r['url'] : '';
        if ( '' === $u ) continue;
        $key = tse_strategy_normalise_url( $u );
        $rel = isset( $r['relationships'] ) && is_array( $r['relationships'] ) ? $r['relationships'] : array();
        $aut = isset( $r['authority'] )     && is_array( $r['authority'] )     ? $r['authority']     : array();
        $snap[ $key ] = array(
            'url'                       => $u,
            'title'                     => isset( $r['title'] ) ? (string) $r['title'] : '',
            'incoming_link_count'       => isset( $rel['incoming_link_count'] ) ? (int) $rel['incoming_link_count'] : 0,
            'unique_linking_pages'      => isset( $rel['unique_linking_pages'] ) ? (int) $rel['unique_linking_pages'] : 0,
            'incoming_anchors'          => isset( $rel['incoming_anchors'] ) && is_array( $rel['incoming_anchors'] ) ? $rel['incoming_anchors'] : array(),
            'inbound_classifications'   => isset( $rel['inbound_classifications'] ) && is_array( $rel['inbound_classifications'] ) ? $rel['inbound_classifications'] : array(),
            'strategic_type'            => isset( $aut['strategic_type'] ) ? (string) $aut['strategic_type'] : 'other',
            'internal_authority_score'  => isset( $aut['internal_authority_score'] ) ? (float) $aut['internal_authority_score'] : 0,
        );
    }

    // Authority distribution for "below-median" checks.
    $auth_scores = array();
    foreach ( $snap as $s ) $auth_scores[] = (float) $s['internal_authority_score'];
    sort( $auth_scores );
    $median_auth = 0.0;
    $n = count( $auth_scores );
    if ( $n > 0 ) {
        $mid = (int) floor( $n / 2 );
        $median_auth = ( $n % 2 === 0 ) ? ( ( $auth_scores[ $mid - 1 ] + $auth_scores[ $mid ] ) / 2 ) : $auth_scores[ $mid ];
    }

    $declared_resolved   = 0;
    $declared_unresolved = array();

    // Buckets treated as "high-value strategic targets" for the
    // under-linked / below-median rules.
    $strategic_buckets = array(
        'active_strategic_targets',
        'geo_location_targets',
    );

    foreach ( $idx as $norm_url => $buckets ) {
        if ( ! isset( $snap[ $norm_url ] ) ) {
            $declared_unresolved[] = $norm_url;
            continue;
        }
        $declared_resolved++;
        $s = $snap[ $norm_url ];

        $is_strategic = (bool) array_intersect( $buckets, $strategic_buckets );

        // V2.10.3 — Conversion endpoints (declared in primary_conversion_pages
        // OR detected as strategic_type='conversion') must NEVER be flagged
        // as under-linked / weak strategic targets, even if a user
        // accidentally listed them in active_strategic_targets too. They are
        // CTA destinations, not SEO ranking targets.
        $is_conversion_endpoint = in_array( 'primary_conversion_pages', $buckets, true )
            || 'conversion' === ( $s['strategic_type'] ?? '' );

        // ----- 1. Declared strategic target is under-linked.
        if ( $is_strategic && ! $is_conversion_endpoint && $s['incoming_link_count'] <= 2 ) {
            $items[] = array(
                'priority'         => 'high',
                'issue'            => 'Declared strategic target is under-linked',
                'affected_pages'   => array( $s['url'] ),
                'recommendation'   => 'Add at least 3 contextual internal links pointing to ' . $s['url']
                                    . ' from your highest-traffic topical pages.',
                'confidence_score' => 1.0,
                'category'         => 'strategy',
                'declared_buckets' => $buckets,
                'actual_metrics'   => array(
                    'incoming_link_count' => $s['incoming_link_count'],
                    'authority_score'     => round( $s['internal_authority_score'], 2 ),
                ),
            );
        }

        // ----- 2. Declared strategic target below median authority.
        if ( $is_strategic && ! $is_conversion_endpoint && $s['internal_authority_score'] < $median_auth ) {
            $items[] = array(
                'priority'         => 'medium',
                'issue'            => 'Declared strategic target sits below the site-wide median support level',
                'affected_pages'   => array( $s['url'] ),
                'recommendation'   => 'Identify 3–5 strong topical sources and add descriptive internal links to ' . $s['url'] . '.',
                'confidence_score' => 1.0,
                'category'         => 'strategy',
                'declared_buckets' => $buckets,
                'actual_metrics'   => array(
                    'authority_score' => round( $s['internal_authority_score'], 2 ),
                    'median_authority'=> round( $median_auth, 2 ),
                ),
            );
        }

        // ----- 3. Declared priority URL is poorly supported.
        if ( in_array( 'priority_urls', $buckets, true ) && $s['incoming_link_count'] <= 3 ) {
            $items[] = array(
                'priority'         => 'medium',
                'issue'            => 'Declared priority URL has limited internal support',
                'affected_pages'   => array( $s['url'] ),
                'recommendation'   => 'Add internal links to ' . $s['url'] . ' from related supporting content.',
                'confidence_score' => 1.0,
                'category'         => 'strategy',
                'declared_buckets' => $buckets,
                'actual_metrics'   => array( 'incoming_link_count' => $s['incoming_link_count'] ),
            );
        }

        // ----- 4. Declared primary conversion page has no inbound from a strategic target.
        if ( in_array( 'primary_conversion_pages', $buckets, true ) ) {
            $has_inbound = false;
            $inbound_classes = $s['inbound_classifications'];
            foreach ( array( 'money', 'service', 'product', 'category' ) as $cls ) {
                if ( isset( $inbound_classes[ $cls ] ) && (int) $inbound_classes[ $cls ] > 0 ) { $has_inbound = true; break; }
            }
            if ( ! $has_inbound ) {
                $items[] = array(
                    'priority'         => 'high',
                    'issue'            => 'Primary conversion page is not linked to from any strategic target',
                    'affected_pages'   => array( $s['url'] ),
                    'recommendation'   => 'Add a clear in-content call-to-action link from each strategic target page directly to ' . $s['url'] . '.',
                    'confidence_score' => 1.0,
                    'category'         => 'strategy',
                    'declared_buckets' => $buckets,
                );
            }
        }

        // ----- 5. Role conflict: declared support page behaves like a strategic target.
        if ( in_array( 'support_pages', $buckets, true ) && in_array( $s['strategic_type'], array( 'money', 'service' ), true ) ) {
            $items[] = array(
                'priority'         => 'medium',
                'issue'            => 'Declared support page is behaving like a strategic target',
                'affected_pages'   => array( $s['url'] ),
                'recommendation'   => 'Tone down the conversion-style copy on ' . $s['url']
                                    . ' or move it to the strategic-target list. Right now its signals match a service / money page, not a support article.',
                'confidence_score' => 0.9,
                'category'         => 'strategy',
                'declared_buckets' => $buckets,
                'detected_role'    => $s['strategic_type'],
            );
        }

        // ----- 6. Role conflict: declared geo/location target isn't detected as location.
        if ( in_array( 'geo_location_targets', $buckets, true ) && 'location' !== $s['strategic_type'] && '' !== $s['strategic_type'] ) {
            $items[] = array(
                'priority'         => 'low',
                'issue'            => 'Declared location page is not detected as a location by URL / schema signals',
                'affected_pages'   => array( $s['url'] ),
                'recommendation'   => 'Add a clear locality signal to ' . $s['url']
                                    . ' (LocalBusiness schema, town name in the H1, full NAP block) so search engines treat it as a local landing page.',
                'confidence_score' => 0.8,
                'category'         => 'strategy',
                'declared_buckets' => $buckets,
                'detected_role'    => $s['strategic_type'],
            );
        }

        // ----- 7. Protected URL appears in duplicate metadata cluster.
        if ( in_array( 'protected_urls', $buckets, true ) ) {
            $dup_titles = isset( $extras['duplicate_meta_titles'] ) && is_array( $extras['duplicate_meta_titles'] ) ? $extras['duplicate_meta_titles'] : array();
            foreach ( $dup_titles as $d ) {
                $urls = isset( $d['urls'] ) ? (array) $d['urls'] : array();
                foreach ( $urls as $u ) {
                    if ( tse_strategy_normalise_url( $u ) === $norm_url ) {
                        $items[] = array(
                            'priority'         => 'high',
                            'issue'            => 'Protected URL shares its meta title with another page',
                            'affected_pages'   => $urls,
                            'recommendation'   => 'Rewrite the meta title of the OTHER pages in this duplicate set — leave ' . $s['url'] . ' untouched (protected).',
                            'confidence_score' => 1.0,
                            'category'         => 'strategy',
                            'declared_buckets' => $buckets,
                        );
                        break 2;
                    }
                }
            }
        }
    }

    return array(
        'description'  => 'Deterministic mismatch between declared SEO strategy and observed internal architecture.',
        'generated_at' => gmdate( 'c' ),
        'totals'       => array(
            'declared_total'      => array_sum( array_map( 'count', $strategy ) ),
            'declared_resolved'   => $declared_resolved,
            'declared_unresolved' => count( $declared_unresolved ),
            'mismatch_findings'   => count( $items ),
        ),
        'unresolved_declared_urls' => array_values( $declared_unresolved ),
        'items'        => $items,
    );
}

/* -------------------------------------------------------------------------
 * Admin UI rendering + save handler
 * ---------------------------------------------------------------------- */

function tse_strategy_render_admin_section() {
    $strategy   = tse_strategy_get();
    $buckets    = tse_strategy_buckets();
    $action_url = admin_url( 'admin-post.php' );
    ?>
    <hr style="margin:32px 0">
    <h2 style="display:flex;align-items:center;gap:8px" data-testid="tse-strategy-heading">
        <?php echo esc_html__( 'Strategic SEO Configuration', 'tse-site-exporter' ); ?>
        <span style="font-size:12px;color:#646970;font-weight:normal">
            <?php echo esc_html__( 'Optional. Declare your intent so the analysis can flag declared-vs-actual gaps.', 'tse-site-exporter' ); ?>
        </span>
    </h2>
    <p style="max-width:760px;color:#555">
        <?php echo esc_html__( 'One URL or path per line. Lines beginning with # are treated as comments. Trailing slashes and protocol differences are normalised automatically.', 'tse-site-exporter' ); ?>
    </p>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>">
        <input type="hidden" name="action" value="tse_site_exporter_strategy_save" />
        <?php wp_nonce_field( TSE_STRATEGY_NONCE, 'tse_strategy_nonce' ); ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:16px;max-width:1100px">
            <?php foreach ( $buckets as $key => $meta ) :
                $value = implode( "\n", isset( $strategy[ $key ] ) ? $strategy[ $key ] : array() );
            ?>
                <div style="background:#fff;border:1px solid #dcdcde;border-radius:4px;padding:14px">
                    <label for="tse-strategy-<?php echo esc_attr( $key ); ?>" style="font-weight:600;display:block;margin-bottom:4px">
                        <?php echo esc_html( $meta['label'] ); ?>
                    </label>
                    <p style="margin:0 0 6px 0;color:#646970;font-size:12px"><?php echo esc_html( $meta['help'] ); ?></p>
                    <textarea
                        id="tse-strategy-<?php echo esc_attr( $key ); ?>"
                        name="<?php echo esc_attr( $key ); ?>"
                        rows="5"
                        style="width:100%;font-family:ui-monospace,SFMono-Regular,monospace;font-size:12px"
                        placeholder="<?php echo esc_attr( $meta['placeholder'] ); ?>"
                        data-testid="tse-strategy-<?php echo esc_attr( $key ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
                    <p style="margin:4px 0 0 0;font-size:11px;color:#646970">
                        <?php echo esc_html( sprintf( __( '%d entries', 'tse-site-exporter' ), count( isset( $strategy[ $key ] ) ? $strategy[ $key ] : array() ) ) ); ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>

        <p style="margin-top:16px">
            <button type="submit" class="button button-primary" data-testid="tse-strategy-save">
                <?php echo esc_html__( 'Save Strategy', 'tse-site-exporter' ); ?>
            </button>
            <span style="margin-left:12px;color:#646970;font-size:12px">
                <?php echo esc_html__( 'Stored in wp_options.tse_site_exporter_strategy. Read on every export + AI run.', 'tse-site-exporter' ); ?>
            </span>
        </p>
    </form>
    <?php
}

function tse_strategy_handle_save() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Insufficient permissions.', 'tse-site-exporter' ), '', array( 'response' => 403 ) );
    }
    check_admin_referer( TSE_STRATEGY_NONCE, 'tse_strategy_nonce' );

    tse_strategy_save( $_POST );

    wp_safe_redirect( add_query_arg( array(
        'page'              => 'tse-site-exporter',
        'tse_strategy_saved'=> '1',
    ), admin_url( 'tools.php' ) ) );
    exit;
}
add_action( 'admin_post_tse_site_exporter_strategy_save', 'tse_strategy_handle_save' );
