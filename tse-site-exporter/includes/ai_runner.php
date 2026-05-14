<?php
/**
 * TSE Site Exporter — AI Analysis Execution runner (V2.5.0).
 *
 * Consumes the compact ai-*.json datasets produced by tse_ai_summary_build()
 * and dispatches four targeted LLM prompts via the configured provider.
 *
 * Each output adheres to a strict shared schema:
 *   { "items": [ {
 *       "priority":         "high|medium|low",
 *       "issue":            "short description",
 *       "affected_pages":   [ "url1", ... ],
 *       "recommendation":   "specific action",
 *       "confidence_score": 0.0..1.0,
 *       ...optional extras documented per-prompt...
 *   }, ... ] }
 *
 * No essay-style output is requested anywhere.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry. Returns array of filename => payload.
 *
 * @param object $provider TSE_AI_Provider_* instance.
 * @param array  $inputs   ai-site, ai-pages, ai-linking, ai-cluster (already compact).
 */
function tse_ai_runner_execute( $provider, $inputs, $opts = array() ) {
    $defaults = array(
        'max_items'   => 20,
        'temperature' => 0.2,
        'timeout'     => 90,
    );
    $opts = array_merge( $defaults, $opts );

    $results = array(
        'ai-recommendations.json'           => tse_ai_runner_recommendations( $provider, $inputs, $opts ),
        'ai-internal-link-opportunities.json' => tse_ai_runner_link_opportunities( $provider, $inputs, $opts ),
        'ai-cluster-analysis.json'          => tse_ai_runner_cluster_analysis( $provider, $inputs, $opts ),
        'ai-content-gap-signals.json'       => tse_ai_runner_content_gap( $provider, $inputs, $opts ),
    );

    $manifest = array(
        'plugin'         => 'TSE Site Exporter',
        'plugin_version' => TSE_SITE_EXPORTER_VERSION,
        'generated_at'   => gmdate( 'c' ),
        'provider'       => $provider->slug(),
        'model'          => $provider->get_model(),
        'site_url'       => home_url(),
        'site_name'      => get_bloginfo( 'name' ),
        'options'        => array(
            'max_items'   => $opts['max_items'],
            'temperature' => $opts['temperature'],
            'timeout'     => $opts['timeout'],
        ),
        'files'          => array_keys( $results ),
    );

    return array( 'manifest.json' => $manifest ) + $results;
}

/* -------------------------------------------------------------------------
 * Prompt 1 — Prioritised recommendations
 * ---------------------------------------------------------------------- */
function tse_ai_runner_recommendations( $provider, $inputs, $opts ) {
    $payload = array(
        'site_summary'   => $inputs['site'],
        'linking_summary'=> $inputs['linking'],
    );
    $system = "You are a senior technical SEO consultant analysing pre-computed site signals (PageRank-like authority, strategic page classification, cluster structure, on-page issue flags). "
            . "Return ONLY a JSON object matching this schema: "
            . '{"items":[{"priority":"high|medium|low","issue":"<short>","affected_pages":["url"],"recommendation":"<short specific action>","confidence_score":0.0_to_1.0,"category":"linking|authority|metadata|content|cluster|cannibalisation"}]}. '
            . "Hard rules: max " . (int) $opts['max_items'] . " items; one issue per item; recommendation must be a concrete, executable action; "
            . "affected_pages must come from the provided data only (do not invent URLs); no prose, no markdown.";
    $resp = $provider->complete( $system, $payload, $opts );
    return tse_ai_runner_wrap( $resp, 'prioritised_recommendations' );
}

/* -------------------------------------------------------------------------
 * Prompt 2 — Internal link opportunities (refined + anchor suggestions)
 * ---------------------------------------------------------------------- */
function tse_ai_runner_link_opportunities( $provider, $inputs, $opts ) {
    // Build a slim payload: the pre-computed opportunities + thin page context
    // for source/target nodes only.
    $opportunities = isset( $inputs['linking']['linking_opportunities'] ) ? $inputs['linking']['linking_opportunities'] : array();
    $urls_in_play  = array();
    foreach ( $opportunities as $o ) {
        if ( isset( $o['source_url'] ) ) $urls_in_play[ $o['source_url'] ] = true;
        if ( isset( $o['target_url'] ) ) $urls_in_play[ $o['target_url'] ] = true;
    }
    $page_context = array();
    foreach ( $inputs['pages'] as $p ) {
        if ( isset( $urls_in_play[ $p['url'] ] ) ) {
            $page_context[] = array(
                'url'                     => $p['url'],
                'title'                   => $p['title'],
                'strategic_type'          => $p['strategic_type'],
                'internal_authority_score'=> $p['internal_authority_score'],
                'incoming_link_count'     => $p['incoming_link_count'],
                'outgoing_link_count'     => $p['outgoing_link_count'],
                'top_inbound_anchors'     => $p['top_inbound_anchors'],
                'issues'                  => $p['issues'],
            );
        }
    }

    $payload = array(
        'pre_computed_opportunities' => $opportunities,
        'page_context'               => $page_context,
        'weak_money_pages'           => isset( $inputs['linking']['weak_money_pages'] ) ? $inputs['linking']['weak_money_pages'] : array(),
        'orphan_pages'               => isset( $inputs['linking']['orphan_pages'] ) ? $inputs['linking']['orphan_pages'] : array(),
        'near_orphan_pages'          => isset( $inputs['linking']['near_orphan_pages'] ) ? $inputs['linking']['near_orphan_pages'] : array(),
    );

    $system = "You are an internal-linking specialist. Refine the supplied pre-computed link opportunities and add anchor-text suggestions grounded in target page titles. "
            . "Return ONLY a JSON object matching: "
            . '{"items":[{"priority":"high|medium|low","issue":"<short>","affected_pages":["<source_url>","<target_url>"],"recommendation":"<short action>","confidence_score":0.0_to_1.0,"source_url":"...","target_url":"...","suggested_anchor":"<descriptive anchor>","reason":"<why this lifts target authority>"}]}. '
            . "Hard rules: max " . (int) $opts['max_items'] . " items; only use URLs present in the input; reject generic anchors (\"click here\", \"read more\"); "
            . "prioritise lifts to under-supported money/service/location pages; no prose, no markdown.";
    $resp = $provider->complete( $system, $payload, $opts );
    return tse_ai_runner_wrap( $resp, 'internal_link_opportunities' );
}

/* -------------------------------------------------------------------------
 * Prompt 3 — Cluster analysis
 * ---------------------------------------------------------------------- */
function tse_ai_runner_cluster_analysis( $provider, $inputs, $opts ) {
    $payload = array(
        'cluster_summary' => $inputs['cluster'],
        'top_authorities' => isset( $inputs['site']['top_authorities'] ) ? $inputs['site']['top_authorities'] : array(),
    );
    $system = "You are analysing internal-link clusters (weakly connected components of the site graph). "
            . "For each meaningful cluster (isolated, under-supported, or main with structural issues), return one or more findings. "
            . "Return ONLY a JSON object matching: "
            . '{"items":[{"priority":"high|medium|low","cluster_id":<int>,"issue":"<short>","affected_pages":["url"],"recommendation":"<short action>","confidence_score":0.0_to_1.0,"finding_type":"isolated|under_supported|hub_concentration|fragmented|other"}]}. '
            . "Hard rules: max " . (int) $opts['max_items'] . " items; affected_pages must be real cluster members; for every isolated cluster, recommend a specific bridge source URL if available; no prose, no markdown.";
    $resp = $provider->complete( $system, $payload, $opts );
    return tse_ai_runner_wrap( $resp, 'cluster_analysis' );
}

/* -------------------------------------------------------------------------
 * Prompt 4 — Content gap signals
 * ---------------------------------------------------------------------- */
function tse_ai_runner_content_gap( $provider, $inputs, $opts ) {
    // Slim page list: just enough to reason about gaps and overlap.
    $slim_pages = array();
    foreach ( $inputs['pages'] as $p ) {
        $slim_pages[] = array(
            'url'                  => $p['url'],
            'title'                => $p['title'],
            'meta_title'           => $p['meta_title'],
            'meta_description'    => $p['meta_description'],
            'strategic_type'       => $p['strategic_type'],
            'classification'       => $p['classification'],
            'h1'                   => $p['h1'],
            'h2'                   => $p['h2'],
            'word_count'           => $p['word_count'],
            'issues'               => $p['issues'],
        );
    }

    $payload = array(
        'pages'                       => $slim_pages,
        'duplicate_meta_titles'       => isset( $inputs['linking']['duplicate_meta_titles'] ) ? $inputs['linking']['duplicate_meta_titles'] : array(),
        'duplicate_meta_descriptions' => isset( $inputs['linking']['duplicate_meta_descriptions'] ) ? $inputs['linking']['duplicate_meta_descriptions'] : array(),
        'strategic_distribution'      => isset( $inputs['site']['distribution']['by_strategic_type'] ) ? $inputs['site']['distribution']['by_strategic_type'] : array(),
    );

    $system = "You are detecting content gaps, missing support content, and cannibalisation/overlap signals from compact page summaries. "
            . "Return ONLY a JSON object matching: "
            . '{"items":[{"priority":"high|medium|low","issue":"<short>","affected_pages":["url"],"recommendation":"<short action>","confidence_score":0.0_to_1.0,"gap_type":"missing_support|missing_money|cannibalisation|thin_content|metadata_weak|topic_overlap|other"}]}. '
            . "Hard rules: max " . (int) $opts['max_items'] . " items; only reference URLs present in the input; flag cannibalisation when two URLs share near-identical H1 or meta_title for the same strategic_type; "
            . "flag missing_support when a strategic_type has high-authority money/service pages but no support pages cover the same topic family; no prose, no markdown.";
    $resp = $provider->complete( $system, $payload, $opts );
    return tse_ai_runner_wrap( $resp, 'content_gap_signals' );
}

/* -------------------------------------------------------------------------
 * Response wrapper: normalise success/error payloads to a stable shape.
 * ---------------------------------------------------------------------- */
function tse_ai_runner_wrap( $resp, $kind ) {
    if ( is_wp_error( $resp ) ) {
        return array(
            'kind'    => $kind,
            'status'  => 'error',
            'error'   => $resp->get_error_message(),
            'error_code' => $resp->get_error_code(),
            'error_data' => $resp->get_error_data(),
            'items'   => array(),
        );
    }
    $items = array();
    if ( is_array( $resp ) ) {
        if ( isset( $resp['items'] ) && is_array( $resp['items'] ) ) {
            $items = $resp['items'];
        } else {
            // The model returned a JSON object but without an items wrapper.
            // Wrap a single object as one item if it looks like a finding.
            if ( isset( $resp['issue'] ) || isset( $resp['recommendation'] ) ) {
                $items = array( $resp );
            }
        }
    }

    return array(
        'kind'      => $kind,
        'status'    => 'ok',
        'count'     => count( $items ),
        'items'     => $items,
    );
}
