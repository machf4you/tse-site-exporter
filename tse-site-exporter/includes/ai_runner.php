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
        'site_summary'    => $inputs['site'],
        'linking_summary' => $inputs['linking'],
        'strategy'        => isset( $inputs['strategy'] ) ? $inputs['strategy'] : null,
    );
    $system = "You are a senior technical SEO consultant. The data has already been crunched — your job is to produce CLEAR, ACTIONABLE recommendations a junior marketer can execute today. "
            . "Return ONLY a JSON object matching this schema: "
            . '{"items":[{"priority":"high|medium|low","issue":"<short, plain-English>","affected_pages":["url"],"recommendation":"<imperative action>","confidence_score":0.0_to_1.0,"category":"linking|authority|metadata|content|cluster|cannibalisation|strategy"}]}. '
            . "WORDING RULES (strict): "
            . "(1) Start every `recommendation` with an imperative verb (Add, Rewrite, Remove, Merge, Redirect, Set, Replace). "
            . "(2) Plain English only. BANNED terms: 'authority', 'link juice', 'link equity', 'pass strong', 'topical relevance signals', 'PageRank', 'siloing'. "
            . "(3) `issue` must describe WHAT is wrong in one short sentence (no jargon). The WHY belongs in `recommendation` as an optional trailing clause beginning with 'so that' / 'because'. "
            . "(4) For Elementor warnings, write what to change in the template, not 'global template'. "
            . "(5) For duplicate metadata, name the conflicting pages and tell the user which to rewrite. "
            . "(6) For orphan pages, suggest a specific source page to link from. "
            . "(7) For cannibalisation, recommend one of: 'Merge', 'Redirect via 301', or 'Differentiate the intent of'. "
            . "(8) If the input includes a `strategy.items` block, treat those declared-vs-actual gaps as priority context and reflect them in your output where relevant. "
            . "Hard rules: max " . (int) $opts['max_items'] . " items; one issue per item; "
            . "affected_pages must come from the provided data only (do not invent URLs); no prose outside the JSON, no markdown.";
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
        'strategy'                   => isset( $inputs['strategy'] ) ? $inputs['strategy'] : null,
    );

    $system = "You are an internal-linking specialist writing implementation-ready link instructions. "
            . "Each item must read like a Jira ticket, not an SEO essay. "
            . "Return ONLY a JSON object matching: "
            . '{"items":[{"priority":"high|medium|low","issue":"<short>","affected_pages":["<source_url>","<target_url>"],"recommendation":"<imperative action>","confidence_score":0.0_to_1.0,"source_url":"...","target_url":"...","suggested_anchor":"<descriptive 2-5 word anchor>","reason":"<one-sentence plain-English why>"}]}. '
            . "WORDING RULES (strict): "
            . "(1) `recommendation` starts with 'Add an internal link from <source path> to <target path> using anchor \"<anchor>\".'. No jargon. "
            . "(2) `suggested_anchor` must be 2-5 words, descriptive, derived from the target page title. Reject 'click here', 'read more', 'learn more', 'this page'. "
            . "(3) `reason` is ONE sentence in plain English explaining the user benefit — e.g. 'this page covers the exact question someone reading the source is likely to ask next'. "
            . "Do NOT write 'passes authority', 'link equity', 'PageRank', 'topical relevance signals'. "
            . "(4) If the input includes a declared strategy (`strategy.buckets.money_pages` etc), prioritise lifts towards those declared targets first. "
            . "Hard rules: max " . (int) $opts['max_items'] . " items; only use URLs present in the input; "
            . "no prose outside the JSON, no markdown.";
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
        'strategy'        => isset( $inputs['strategy'] ) ? $inputs['strategy'] : null,
    );
    $system = "You are analysing internal-link clusters (weakly connected components of the site graph). Write findings as if you were briefing a developer — plain English, one action per finding. "
            . "Return ONLY a JSON object matching: "
            . '{"items":[{"priority":"high|medium|low","cluster_id":<int>,"issue":"<short, plain-English>","affected_pages":["url"],"recommendation":"<imperative action>","confidence_score":0.0_to_1.0,"finding_type":"isolated|under_supported|hub_concentration|fragmented|other"}]}. '
            . "WORDING RULES: "
            . "(1) `recommendation` starts with an imperative verb (Add, Link, Merge, Split, Move). "
            . "(2) For every isolated cluster, recommend ONE specific bridge: 'Add an internal link from <hub URL> to <cluster member URL> using anchor \"<descriptive anchor>\".'. "
            . "(3) No jargon — banned: 'authority distribution', 'link equity', 'PageRank flow', 'topical silo'. "
            . "(4) If the input includes a `strategy` block, isolated clusters that contain declared money / priority pages MUST be priority='high'. "
            . "Hard rules: max " . (int) $opts['max_items'] . " items; affected_pages must be real cluster members; no prose outside the JSON, no markdown.";
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
            'url'              => isset( $p['url'] ) ? $p['url'] : '',
            'title'            => isset( $p['title'] ) ? $p['title'] : '',
            'meta_title'       => isset( $p['meta_title'] ) ? $p['meta_title'] : '',
            'meta_description' => isset( $p['meta_description'] ) ? $p['meta_description'] : '',
            'strategic_type'   => isset( $p['strategic_type'] ) ? $p['strategic_type'] : 'other',
            'classification'   => isset( $p['classification'] ) ? $p['classification'] : '',
            'h1'               => isset( $p['h1'] ) ? $p['h1'] : '',
            'h2'               => isset( $p['h2'] ) ? $p['h2'] : array(),
            'word_count'       => isset( $p['word_count'] ) ? (int) $p['word_count'] : 0,
            'issues'           => isset( $p['issues'] ) ? $p['issues'] : array(),
        );
    }

    $payload = array(
        'pages'                       => $slim_pages,
        'duplicate_meta_titles'       => isset( $inputs['linking']['duplicate_meta_titles'] ) ? $inputs['linking']['duplicate_meta_titles'] : array(),
        'duplicate_meta_descriptions' => isset( $inputs['linking']['duplicate_meta_descriptions'] ) ? $inputs['linking']['duplicate_meta_descriptions'] : array(),
        'strategic_distribution'      => isset( $inputs['site']['distribution']['by_strategic_type'] ) ? $inputs['site']['distribution']['by_strategic_type'] : array(),
    );

    $system = "You are detecting content gaps, missing support content, and cannibalisation/overlap signals from compact page summaries. Your output is read by a non-SEO marketer — write in plain English. "
            . "Return ONLY a JSON object matching: "
            . '{"items":[{"priority":"high|medium|low","issue":"<short, plain-English>","affected_pages":["url"],"recommendation":"<imperative action>","confidence_score":0.0_to_1.0,"gap_type":"missing_support|missing_money|cannibalisation|thin_content|metadata_weak|topic_overlap|other"}]}. '
            . "WORDING RULES (strict): "
            . "(1) For cannibalisation: name the conflicting pages and recommend exactly one of 'Merge', 'Redirect via 301', or 'Differentiate the intent of'. "
            . "(2) For duplicate metadata: tell the user WHICH page to rewrite (default: the lower-authority / less-trafficked one) and give a one-line suggestion. "
            . "(3) For thin content: say 'Expand to X words covering Y' — no jargon. "
            . "(4) For missing_support: recommend a specific support topic to write, naming the money page it would support. "
            . "(5) BANNED phrases: 'topical authority', 'link equity', 'thin from a keyword perspective', 'optimise for'. "
            . "(6) If the input includes a `strategy` block, items affecting declared money / priority / primary_conversion / location URLs MUST be marked priority='high'. Protected URLs MUST NOT be recommended for redirect / merge / noindex — recommend changes to the OTHER conflicting page instead. "
            . "Hard rules: max " . (int) $opts['max_items'] . " items; only reference URLs present in the input; "
            . "flag cannibalisation when two URLs share near-identical H1 or meta_title for the same strategic_type; "
            . "flag missing_support when a strategic_type has high-authority money/service pages but no support pages cover the same topic family; no prose outside the JSON, no markdown.";
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
