<?php
/**
 * TSE Site Exporter — Static HTML reports (V2.7.0).
 *
 * Self-contained, framework-free HTML reports rendered from the AI runner
 * outputs PLUS the deterministic AI-summary inputs.
 *
 * V2.7 readability pass adds:
 *  - Wider layout (~1600px max), sticky table headers
 *  - Collapsible affected-pages lists (>3 pages → native <details>)
 *  - Recommendations grouped by type (Metadata / Linking / Cannibalisation /
 *    Thin Content / Authority / Cluster·Architecture / Other)
 *  - Estimated SEO impact column (separate from confidence)
 *  - "Suggested priority order" 3-column block at top (Fix first / next / later)
 *  - Export summary metrics strip (totals + breakdowns)
 *
 * Three reports produced (filenames unchanged):
 *   - ai-report.html
 *   - internal-link-report.html
 *   - cluster-report.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public entry. Returns array of filename => HTML string.
 *
 * @param array $runner_output {filename => payload} map from runner.
 * @param array $context       Optional deterministic AI summary slices:
 *                             {pages, linking, site, cluster}.
 */
function tse_ai_report_build( $runner_output, $context = array() ) {
    $meta = isset( $runner_output['manifest.json'] ) ? $runner_output['manifest.json'] : array();

    $recs     = isset( $runner_output['ai-recommendations.json'] ) ? $runner_output['ai-recommendations.json'] : array( 'items' => array() );
    $links    = isset( $runner_output['ai-internal-link-opportunities.json'] ) ? $runner_output['ai-internal-link-opportunities.json'] : array( 'items' => array() );
    $clusters = isset( $runner_output['ai-cluster-analysis.json'] ) ? $runner_output['ai-cluster-analysis.json'] : array( 'items' => array() );
    $gaps     = isset( $runner_output['ai-content-gap-signals.json'] ) ? $runner_output['ai-content-gap-signals.json'] : array( 'items' => array() );

    $page_index = tse_ai_report_build_page_index( $context );

    return array(
        'ai-report.html'            => tse_ai_report_main( $meta, $recs, $gaps, $links, $context, $page_index ),
        'internal-link-report.html' => tse_ai_report_links( $meta, $links, $page_index ),
        'cluster-report.html'       => tse_ai_report_clusters( $meta, $clusters, $page_index ),
    );
}

/* -------------------------------------------------------------------------
 * Context normalisation: build URL → metadata index.
 * ---------------------------------------------------------------------- */
function tse_ai_report_build_page_index( $context ) {
    $idx = array();
    if ( empty( $context['pages'] ) || ! is_array( $context['pages'] ) ) return $idx;

    $type_label = array(
        'money'    => 'Money Page',
        'service'  => 'Service Page',
        'location' => 'Location Page',
        'product'  => 'Product Page',
        'category' => 'Category Page',
        'article'  => 'Support Article',
        'support'  => 'Support Page',
        'homepage' => 'Homepage',
        'other'    => 'Page',
    );
    $type_color = array(
        'money'    => 'money',    'service'  => 'service',  'location' => 'location',
        'product'  => 'product',  'category' => 'product',  'article'  => 'article',
        'support'  => 'support',  'homepage' => 'home',     'other'    => 'neutral',
    );

    foreach ( $context['pages'] as $p ) {
        $url = isset( $p['url'] ) ? (string) $p['url'] : '';
        if ( '' === $url ) continue;
        $title = isset( $p['title'] ) ? trim( (string) $p['title'] ) : '';
        $st    = isset( $p['strategic_type'] ) ? (string) $p['strategic_type'] : 'other';
        $parts = wp_parse_url( $url );
        $path  = '/';
        if ( isset( $parts['path'] ) && '' !== $parts['path'] ) $path = rtrim( $parts['path'], '/' ) . '/';
        if ( '' === $path ) $path = '/';

        $entry = array(
            'title'           => '' !== $title ? $title : null,
            'path'            => $path,
            'strategic_type'  => $st,
            'page_type_label' => isset( $type_label[ $st ] ) ? $type_label[ $st ] : $type_label['other'],
            'page_type_class' => isset( $type_color[ $st ] ) ? $type_color[ $st ] : 'neutral',
        );
        $idx[ $url ] = $entry;
        $alt  = rtrim( $url, '/' );      if ( $alt  !== $url ) $idx[ $alt ]  = $entry;
        $alt2 = $url . '/';              if ( $alt2 !== $url ) $idx[ $alt2 ] = $entry;
    }
    return $idx;
}

function tse_ai_report_lookup_page( $url, $page_index ) {
    if ( isset( $page_index[ $url ] ) ) return $page_index[ $url ];
    $alt = rtrim( $url, '/' );  if ( isset( $page_index[ $alt ] ) )  return $page_index[ $alt ];
    $alt2 = $url . '/';         if ( isset( $page_index[ $alt2 ] ) ) return $page_index[ $alt2 ];
    return null;
}

/* -------------------------------------------------------------------------
 * Shared CSS
 * ---------------------------------------------------------------------- */
function tse_ai_report_css() {
    return <<<CSS
:root { color-scheme: light; }
*,*:before,*:after { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111827; background: #f9fafb; line-height: 1.5; }
.wrap { max-width: 1600px; margin: 0 auto; padding: 32px 32px 64px; }
header.tse-h { background: #111827; color: #f9fafb; padding: 28px 32px; }
header.tse-h .inner { max-width: 1600px; margin: 0 auto; }
header.tse-h h1 { margin: 0 0 6px; font-size: 22px; font-weight: 600; letter-spacing: -0.01em; }
header.tse-h .meta { font-size: 13px; color: #9ca3af; display: flex; flex-wrap: wrap; gap: 16px; margin-top: 8px; }
header.tse-h .meta span strong { color: #e5e7eb; font-weight: 500; }
h2.section { font-size: 16px; font-weight: 600; margin: 32px 0 8px; color: #111827; letter-spacing: -0.005em; display:flex; align-items:center; gap:10px; }
h2.section .count { font-size: 12px; font-weight: 500; color: #6b7280; background: #f3f4f6; padding: 2px 8px; border-radius: 999px; }
h3.subsection { font-size: 14px; font-weight: 600; margin: 22px 0 8px; color: #374151; display:flex; align-items:center; gap:8px; }
h3.subsection .count { font-size: 11px; font-weight: 500; color: #6b7280; background: #f3f4f6; padding: 2px 8px; border-radius: 999px; }
.why { font-size: 13px; color: #6b7280; font-style: italic; margin: -2px 0 12px; }
.empty { background: #ffffff; border: 1px dashed #d1d5db; border-radius: 8px; padding: 20px; color: #6b7280; font-size: 14px; }
table.tse { width: 100%; border-collapse: separate; border-spacing: 0; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; overflow: hidden; font-size: 14px; table-layout: fixed; }
table.tse th, table.tse td { padding: 12px 14px; text-align: left; vertical-align: top; border-bottom: 1px solid #f3f4f6; word-wrap: break-word; }
table.tse thead th { background: #f9fafb; font-weight: 600; color: #374151; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; position: sticky; top: 0; z-index: 2; box-shadow: inset 0 -1px 0 #e5e7eb; }
table.tse tr:last-child td { border-bottom: 0; }
table.tse td a { color: #2563eb; text-decoration: none; word-break: break-all; }
table.tse td a:hover { text-decoration: underline; }
.badge { display: inline-block; padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; line-height: 1.4; white-space: nowrap; }
.badge.high   { background: #fee2e2; color: #991b1b; }
.badge.medium { background: #fef3c7; color: #92400e; }
.badge.low    { background: #dcfce7; color: #166534; }
.badge.kind   { background: #e0e7ff; color: #3730a3; }
.badge.confidence { background: #f3f4f6; color: #374151; font-variant-numeric: tabular-nums; }
.impact { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 11px; font-weight: 600; line-height: 1.4; white-space: nowrap; border: 1px solid transparent; }
.impact.high   { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
.impact.medium { background: #fffbeb; color: #92400e; border-color: #fde68a; }
.impact.low    { background: #f0fdf4; color: #166534; border-color: #bbf7d0; }
.pt { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; line-height: 1.4; }
.pt.money    { background: #fee2e2; color: #991b1b; }
.pt.service  { background: #ffedd5; color: #9a3412; }
.pt.location { background: #cffafe; color: #155e75; }
.pt.product  { background: #f3e8ff; color: #6b21a8; }
.pt.article  { background: #e0e7ff; color: #3730a3; }
.pt.support  { background: #dcfce7; color: #166534; }
.pt.home     { background: #fef9c3; color: #854d0e; }
.pt.neutral  { background: #f3f4f6; color: #4b5563; }
.pages { display: flex; flex-direction: column; gap: 10px; }
.page-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.page-cell .ptitle { font-weight: 500; color: #111827; font-size: 14px; word-break: break-word; }
.page-cell .ppath { font-size: 12px; color: #6b7280; font-family: ui-monospace, SFMono-Regular, monospace; word-break: break-all; }
.page-cell .ppath a { color: #6b7280; }
.page-cell .ppath a:hover { color: #2563eb; }
.page-cell .pmeta { margin-top: 3px; display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.pages details { margin-top: 2px; }
.pages details summary { cursor: pointer; color: #2563eb; font-size: 12px; padding: 4px 0; user-select: none; list-style: none; font-weight: 500; }
.pages details summary::-webkit-details-marker { display: none; }
.pages details summary:hover { text-decoration: underline; }
.pages details[open] summary { color: #6b7280; }
.pages details .more { display: flex; flex-direction: column; gap: 10px; margin-top: 8px; }
.recommendation { color: #374151; }
.error { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; padding: 14px 16px; border-radius: 8px; font-size: 14px; margin: 12px 0; }
.error code { background: #fff1f2; padding: 2px 5px; border-radius: 4px; font-size: 12px; }
.anchor-suggest { display: inline-block; background: #ecfdf5; color: #065f46; padding: 2px 8px; border-radius: 4px; font-size: 12px; font-family: ui-monospace, SFMono-Regular, monospace; }
.link-card { background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 20px; margin:12px 0; display:grid; grid-template-columns:90px 1fr; gap:10px 16px; }
.link-card .badge-cell { display:flex; flex-direction:column; gap:8px; align-items:flex-start; }
.link-card .title { font-size:15px; font-weight:600; color:#111827; margin:0 0 8px; letter-spacing:-0.005em; }
.link-card .field { display:grid; grid-template-columns:110px 1fr; gap:8px 14px; align-items:start; padding:6px 0; border-top:1px solid #f3f4f6; }
.link-card .field:first-of-type { border-top:0; }
.link-card .field .lbl { font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; line-height:1.6; }
.link-card .field .val { font-size:14px; color:#111827; word-break:break-word; }
.link-card .field .val a { color:#2563eb; text-decoration:none; }
.link-card .field .val a:hover { text-decoration:underline; }
.link-card .field .val.path { font-family:ui-monospace, SFMono-Regular, monospace; font-size:13px; }
.link-card .field .val.anchor { font-family:ui-monospace, SFMono-Regular, monospace; font-size:13px; background:#ecfdf5; color:#065f46; padding:2px 8px; border-radius:4px; display:inline-block; }
.link-card .field .val.reason { color:#374151; }
.strategy-note { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; padding:10px 14px; border-radius:8px; font-size:13px; margin:12px 0; }
.strategy-note strong { font-weight:600; }
.track { background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:18px 20px; margin:16px 0 24px; }
.track h3 { margin:0 0 6px; font-size:15px; font-weight:600; letter-spacing:-0.005em; }
.track .subtitle { margin:0 0 12px; font-size:13px; color:#6b7280; }
.track .track-label { display:inline-block; font-size:11px; font-weight:600; letter-spacing:0.05em; text-transform:uppercase; padding:3px 10px; border-radius:999px; margin-right:10px; vertical-align:middle; }
.track .track-label.content { background:#dbeafe; color:#1e3a8a; }
.track .track-label.dev     { background:#ede9fe; color:#5b21b6; }
.iss { border-top:1px solid #f3f4f6; padding:14px 0; display:grid; grid-template-columns:90px 1fr 180px; gap:10px 16px; }
.iss:first-of-type { border-top:0; padding-top:6px; }
.iss .meta { display:flex; flex-direction:column; gap:6px; align-items:flex-start; }
.iss .body .title { font-weight:600; font-size:14px; color:#111827; margin:0 0 4px; }
.iss .body .rec   { font-size:13px; color:#374151; margin:4px 0; }
.iss .body .guide { font-size:12px; color:#6b7280; margin:4px 0 2px; font-style:italic; }
.iss .body .pages { font-size:12px; margin:6px 0 0; }
.iss .side { font-size:12px; color:#6b7280; display:flex; flex-direction:column; gap:4px; }
.iss .side .group-pill { background:#f3f4f6; color:#374151; padding:2px 8px; border-radius:999px; font-weight:500; display:inline-block; align-self:flex-start; }
details.metric-card { background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:0; margin:0; }
details.metric-card > summary { list-style:none; cursor:pointer; padding:14px 16px; display:flex; flex-direction:column; gap:4px; }
details.metric-card > summary::-webkit-details-marker { display:none; }
details.metric-card > summary .lbl { font-size:11px; text-transform:uppercase; letter-spacing:0.05em; color:#6b7280; font-weight:600; }
details.metric-card > summary .num { font-size:26px; font-weight:600; color:#111827; font-variant-numeric:tabular-nums; line-height:1; margin-top:2px; }
details.metric-card > summary .sub { font-size:12px; color:#6b7280; }
details.metric-card[open] > summary .num { color:#1d4ed8; }
details.metric-card.h > summary .num { color:#b91c1c; }
details.metric-card.m > summary .num { color:#b45309; }
details.metric-card.l > summary .num { color:#166534; }
details.metric-card > .body { padding:0 16px 14px; border-top:1px solid #f3f4f6; }
details.metric-card > .body .pl { padding-top:10px; font-size:13px; color:#374151; }
details.metric-card > .body .pl ul { margin:6px 0 0; padding-left:18px; }
details.metric-card > .body .pl li { margin:4px 0; line-height:1.4; }
details.metric-card > .body .pl li code { background:#f3f4f6; padding:1px 6px; border-radius:4px; font-size:12px; }
details.metric-card > summary:hover { background:#f9fafb; }
footer.tse-f { color: #6b7280; font-size: 12px; text-align: center; margin: 32px 0 24px; }
.metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin: 12px 0 8px; }
.metrics .m { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
.metrics .m .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }
.metrics .m .num { font-size: 22px; font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; line-height: 1; margin-top: 4px; }
.exec { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 12px 0 8px; }
.exec .card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; }
.exec .card .lbl { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #6b7280; font-weight: 600; }
.exec .card .num { font-size: 26px; font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; line-height: 1; margin-top: 4px; }
.exec .card.h .num { color: #b91c1c; }
.exec .card.m .num { color: #b45309; }
.exec .card.l .num { color: #166534; }
.exec .card .sub { font-size: 12px; color: #6b7280; }
.qw { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 4px 0; margin-top: 4px; }
.qw .qw-row { display: grid; grid-template-columns: 28px 1fr; gap: 12px; padding: 12px 16px; border-bottom: 1px solid #f3f4f6; align-items: flex-start; }
.qw .qw-row:last-child { border-bottom: 0; }
.qw .num-circle { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #111827; color: #ffffff; font-size: 12px; font-weight: 600; margin-top: 2px; }
.qw .qw-row .title { font-weight: 500; }
.qw .qw-row .desc { font-size: 13px; color: #6b7280; margin-top: 3px; }
.qw .qw-row .qw-pages { font-size: 13px; margin-top: 6px; display: flex; flex-direction: column; gap: 8px; }
.priority-order { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin: 12px 0 8px; }
.priority-order .col { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 14px 16px; }
.priority-order .col h4 { margin: 0 0 8px; font-size: 13px; font-weight: 600; display:flex; align-items:center; gap:8px; }
.priority-order .col h4 .pill { font-size: 11px; padding: 2px 8px; border-radius: 999px; font-weight: 600; }
.priority-order .col.first  h4 .pill { background: #fee2e2; color: #991b1b; }
.priority-order .col.next   h4 .pill { background: #fef3c7; color: #92400e; }
.priority-order .col.later  h4 .pill { background: #dcfce7; color: #166534; }
.priority-order ol { margin: 0; padding-left: 18px; font-size: 13px; color: #374151; }
.priority-order ol li { margin-bottom: 8px; line-height: 1.4; }
.priority-order ol li .target { color: #6b7280; font-size: 12px; font-family: ui-monospace, SFMono-Regular, monospace; }
.priority-order .empty-mini { font-size: 13px; color: #9ca3af; font-style: italic; }
@media (max-width: 900px) {
  .priority-order { grid-template-columns: 1fr; }
}
CSS;
}

function tse_ai_report_header( $title, $meta ) {
    $provider = isset( $meta['provider'] ) ? $meta['provider'] : '';
    $model    = isset( $meta['model'] )    ? $meta['model']    : '';
    $site_url = isset( $meta['site_url'] ) ? $meta['site_url'] : '';
    $site     = isset( $meta['site_name'] )? $meta['site_name']: '';
    $when     = isset( $meta['generated_at'] ) ? $meta['generated_at'] : '';
    $css = tse_ai_report_css();
    $h   = htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' );
    return "<!doctype html>\n"
        . "<html lang=\"en\"><head><meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . "<title>" . $h . "</title>\n"
        . "<style>" . $css . "</style>\n"
        . "</head><body>\n"
        . "<header class=\"tse-h\"><div class=\"inner\">\n"
        . "<h1>" . $h . "</h1>\n"
        . "<div class=\"meta\">"
        . "<span><strong>" . htmlspecialchars( (string) $site, ENT_QUOTES, 'UTF-8' ) . "</strong></span>"
        . ( $site_url ? "<span>" . htmlspecialchars( (string) $site_url, ENT_QUOTES, 'UTF-8' ) . "</span>" : "" )
        . ( $provider ? "<span>Provider: <strong>" . htmlspecialchars( (string) $provider, ENT_QUOTES, 'UTF-8' ) . "</strong></span>" : "" )
        . ( $model    ? "<span>Model: <strong>"    . htmlspecialchars( (string) $model,    ENT_QUOTES, 'UTF-8' ) . "</strong></span>" : "" )
        . ( $when     ? "<span>" . htmlspecialchars( (string) $when, ENT_QUOTES, 'UTF-8' ) . "</span>" : "" )
        . "</div></div></header>\n"
        . "<div class=\"wrap\">\n";
}

function tse_ai_report_footer() {
    return "</div>\n<footer class=\"tse-f\">Generated by TSE Site Exporter. All findings are LLM-generated and should be reviewed by a human.</footer>\n"
        . "</body></html>\n";
}

/* -------------------------------------------------------------------------
 * Helpers — pages, badges, sorting
 * ---------------------------------------------------------------------- */
function tse_ai_report_page_cell( $url, $page_index, $show_pill = true ) {
    $url_safe = htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
    $hit = tse_ai_report_lookup_page( $url, $page_index );
    $title = $hit && ! empty( $hit['title'] ) ? $hit['title'] : '';
    $path  = $hit && ! empty( $hit['path'] )  ? $hit['path']  : $url;
    $path_safe = htmlspecialchars( (string) $path, ENT_QUOTES, 'UTF-8' );

    $out = '<div class="page-cell">';
    if ( '' !== $title ) {
        $out .= '<span class="ptitle">' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . '</span>';
        $out .= '<span class="ppath"><a href="' . $url_safe . '" target="_blank" rel="noopener">' . $path_safe . '</a></span>';
    } else {
        $out .= '<span class="ptitle"><a href="' . $url_safe . '" target="_blank" rel="noopener">' . $url_safe . '</a></span>';
    }
    if ( $show_pill && $hit ) {
        $out .= '<div class="pmeta"><span class="pt ' . htmlspecialchars( $hit['page_type_class'], ENT_QUOTES, 'UTF-8' ) . '">'
              . htmlspecialchars( $hit['page_type_label'], ENT_QUOTES, 'UTF-8' ) . '</span></div>';
    }
    return $out . '</div>';
}

/**
 * Render a list of affected pages. Shows the first 3 by default; the rest
 * collapse into a native <details> "Show N more pages" element (no JS).
 */
function tse_ai_report_pages_cell( $urls, $page_index ) {
    if ( empty( $urls ) || ! is_array( $urls ) ) return '<span style="color:#9ca3af">—</span>';
    $urls   = array_values( $urls );
    $visible = array_slice( $urls, 0, 3 );
    $extra   = array_slice( $urls, 3 );

    $out = '<div class="pages">';
    foreach ( $visible as $u ) {
        $out .= tse_ai_report_page_cell( $u, $page_index, false );
    }
    if ( ! empty( $extra ) ) {
        $more = count( $extra );
        $out .= '<details><summary>Show ' . $more . ' more page' . ( $more === 1 ? '' : 's' ) . '</summary><div class="more">';
        foreach ( $extra as $u ) {
            $out .= tse_ai_report_page_cell( $u, $page_index, false );
        }
        $out .= '</div></details>';
    }
    return $out . '</div>';
}

function tse_ai_report_priority_class( $p ) {
    $p = strtolower( (string) $p );
    return in_array( $p, array( 'high', 'medium', 'low' ), true ) ? $p : 'low';
}
function tse_ai_report_priority_rank( $p ) {
    $map = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
    $p   = strtolower( (string) $p );
    return isset( $map[ $p ] ) ? $map[ $p ] : 3;
}
function tse_ai_report_sort_by_priority( $items ) {
    usort( $items, function( $a, $b ) {
        $pa = tse_ai_report_priority_rank( isset( $a['priority'] ) ? $a['priority'] : '' );
        $pb = tse_ai_report_priority_rank( isset( $b['priority'] ) ? $b['priority'] : '' );
        if ( $pa === $pb ) {
            $ca = isset( $a['confidence_score'] ) ? (float) $a['confidence_score'] : 0;
            $cb = isset( $b['confidence_score'] ) ? (float) $b['confidence_score'] : 0;
            return $ca === $cb ? 0 : ( $ca < $cb ? 1 : -1 );
        }
        return $pa < $pb ? -1 : 1;
    } );
    return $items;
}

function tse_ai_report_badge( $text, $class = '' ) {
    return '<span class="badge ' . $class . '">' . htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ) . '</span>';
}
function tse_ai_report_confidence_badge( $score ) {
    if ( ! is_numeric( $score ) ) return '';
    $pct = (int) round( ( (float) $score ) * 100 );
    return '<span class="badge confidence">' . $pct . '%</span>';
}

function tse_ai_report_render_error( $data ) {
    if ( ! isset( $data['status'] ) || 'error' !== $data['status'] ) return '';
    $msg  = isset( $data['error'] ) ? $data['error'] : 'Unknown error.';
    $code = isset( $data['error_code'] ) ? $data['error_code'] : '';
    $html = '<div class="error">Analysis failed';
    if ( $code ) $html .= ' (<code>' . htmlspecialchars( (string) $code, ENT_QUOTES, 'UTF-8' ) . '</code>)';
    $html .= ': ' . htmlspecialchars( (string) $msg, ENT_QUOTES, 'UTF-8' ) . '</div>';
    return $html;
}

function tse_ai_report_section_heading( $label, $count ) {
    return '<h2 class="section">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
        . '<span class="count">' . (int) $count . '</span></h2>';
}
function tse_ai_report_subsection_heading( $label, $count ) {
    return '<h3 class="subsection">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
        . '<span class="count">' . (int) $count . '</span></h3>';
}
function tse_ai_report_why( $text ) {
    return '<p class="why">' . htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ) . '</p>';
}

/* -------------------------------------------------------------------------
 * Estimated impact (separate from confidence)
 * ---------------------------------------------------------------------- */
function tse_ai_report_estimated_impact( $item, $page_index ) {
    $priority = strtolower( isset( $item['priority'] ) ? (string) $item['priority'] : '' );
    $conf     = isset( $item['confidence_score'] ) ? (float) $item['confidence_score'] : 0;
    $pages    = isset( $item['affected_pages'] ) && is_array( $item['affected_pages'] ) ? $item['affected_pages'] : array();
    $important_hit = false;
    foreach ( $pages as $u ) {
        $hit = tse_ai_report_lookup_page( $u, $page_index );
        if ( $hit && in_array( $hit['strategic_type'], array( 'money', 'service', 'location', 'product', 'category' ), true ) ) {
            $important_hit = true; break;
        }
    }
    if ( 'high' === $priority && ( $important_hit || $conf >= 0.85 ) ) return 'high';
    if ( 'medium' === $priority && $important_hit )                   return 'high';
    if ( 'high' === $priority )                                       return 'medium';
    if ( 'medium' === $priority )                                     return 'medium';
    return 'low';
}
function tse_ai_report_impact_badge( $level ) {
    $label = strtoupper( $level ) === 'HIGH' ? 'High SEO Impact'
           : ( strtoupper( $level ) === 'MEDIUM' ? 'Medium SEO Impact' : 'Low SEO Impact' );
    return '<span class="impact ' . htmlspecialchars( $level, ENT_QUOTES, 'UTF-8' ) . '">'
         . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</span>';
}

/* -------------------------------------------------------------------------
 * Grouping by display category (Metadata / Linking / Cannibalisation /
 * Thin Content / Authority / Cluster / Other).
 * ---------------------------------------------------------------------- */
function tse_ai_report_group_for( $item ) {
    $cat = strtolower( isset( $item['category'] ) ? (string) $item['category'] : '' );
    $gt  = strtolower( isset( $item['gap_type'] ) ? (string) $item['gap_type'] : '' );
    $issue = strtolower( isset( $item['issue'] ) ? (string) $item['issue'] : '' );
    $rec   = strtolower( isset( $item['recommendation'] ) ? (string) $item['recommendation'] : '' );
    $hay   = $cat . ' ' . $gt . ' ' . $issue . ' ' . $rec;

    if ( in_array( $cat, array( 'metadata' ), true ) || 'metadata_weak' === $gt ) return 'Metadata';
    if ( in_array( $cat, array( 'linking' ), true ) ) return 'Linking';
    if ( in_array( $cat, array( 'cannibalisation', 'cannibalization' ), true )
      || in_array( $gt, array( 'cannibalisation', 'cannibalization', 'topic_overlap' ), true )
      || false !== strpos( $hay, 'cannibal' )
      || false !== strpos( $hay, 'duplicate meta' ) ) return 'Cannibalisation';
    if ( in_array( $cat, array( 'content' ), true ) || 'thin_content' === $gt ) return 'Thin Content';
    if ( in_array( $cat, array( 'authority' ), true ) ) return 'Authority';
    if ( in_array( $cat, array( 'cluster' ), true ) || 'isolated' === ( isset( $item['finding_type'] ) ? strtolower( $item['finding_type'] ) : '' ) ) return 'Cluster / Architecture';
    if ( in_array( $gt, array( 'missing_support', 'missing_money' ), true ) ) return 'Other';
    if ( false !== strpos( $hay, 'meta title' ) || false !== strpos( $hay, 'meta description' ) ) return 'Metadata';
    if ( false !== strpos( $hay, 'internal link' ) || false !== strpos( $hay, 'orphan' ) ) return 'Linking';
    if ( false !== strpos( $hay, 'thin' ) || false !== strpos( $hay, 'word count' ) ) return 'Thin Content';
    if ( false !== strpos( $hay, 'authority' ) ) return 'Authority';
    return 'Other';
}

function tse_ai_report_grouped_table( $items, $page_index ) {
    if ( empty( $items ) ) return '';
    $order = array( 'Metadata', 'Linking', 'Cannibalisation', 'Thin Content', 'Authority', 'Cluster / Architecture', 'Other' );
    $bucket = array();
    foreach ( $items as $it ) {
        $bucket[ tse_ai_report_group_for( $it ) ][] = $it;
    }
    $out = '';
    foreach ( $order as $section ) {
        if ( empty( $bucket[ $section ] ) ) continue;
        $rows = tse_ai_report_sort_by_priority( $bucket[ $section ] );
        $out .= tse_ai_report_subsection_heading( $section, count( $rows ) );
        $out .= tse_ai_report_render_rec_table( $rows, $page_index );
    }
    return $out;
}

function tse_ai_report_render_rec_table( $rows, $page_index ) {
    $html = '<table class="tse"><colgroup>'
          . '<col style="width:80px">'
          . '<col style="width:22%">'
          . '<col style="width:28%">'
          . '<col>'
          . '<col style="width:140px">'
          . '<col style="width:130px">'
          . '</colgroup><thead><tr>'
          . '<th>Priority</th>'
          . '<th>Issue</th>'
          . '<th>Affected pages</th>'
          . '<th>Recommendation</th>'
          . '<th>Impact</th>'
          . '<th>Type / Confidence</th>'
          . '</tr></thead><tbody>';
    foreach ( $rows as $it ) {
        $pr    = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
        $issue = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
        $rec_t = htmlspecialchars( (string) ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ), ENT_QUOTES, 'UTF-8' );
        $cat   = isset( $it['category'] ) ? $it['category'] : ( isset( $it['gap_type'] ) ? $it['gap_type'] : '' );
        $impact= tse_ai_report_estimated_impact( $it, $page_index );
        $html .= '<tr>'
              . '<td>' . tse_ai_report_badge( strtoupper( $pr ), $pr ) . '</td>'
              . '<td>' . $issue . '</td>'
              . '<td>' . tse_ai_report_pages_cell( isset( $it['affected_pages'] ) ? $it['affected_pages'] : array(), $page_index ) . '</td>'
              . '<td class="recommendation">' . $rec_t . '</td>'
              . '<td>' . tse_ai_report_impact_badge( $impact ) . '</td>'
              . '<td>'
              . ( $cat ? tse_ai_report_badge( $cat, 'kind' ) . '<br>' : '' )
              . tse_ai_report_confidence_badge( isset( $it['confidence_score'] ) ? $it['confidence_score'] : null )
              . '</td>'
              . '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

/* -------------------------------------------------------------------------
 * Export Summary Metrics
 * ---------------------------------------------------------------------- */
function tse_ai_report_export_metrics( $context, $links ) {
    $pages = isset( $context['pages'] ) ? $context['pages'] : array();
    $total = count( $pages );
    $by_type = array( 'money' => 0, 'service' => 0, 'support' => 0, 'article' => 0, 'location' => 0, 'product' => 0, 'category' => 0, 'homepage' => 0, 'other' => 0 );
    foreach ( $pages as $p ) {
        $t = isset( $p['strategic_type'] ) ? $p['strategic_type'] : 'other';
        if ( isset( $by_type[ $t ] ) ) $by_type[ $t ]++;
    }
    $support_total = $by_type['support'] + $by_type['article'];
    $orphan       = isset( $context['linking']['orphan_pages'] ) ? count( $context['linking']['orphan_pages'] ) : 0;
    $near_orphan  = isset( $context['linking']['near_orphan_pages'] ) ? count( $context['linking']['near_orphan_pages'] ) : 0;
    $weak_money   = isset( $context['linking']['weak_money_pages'] ) ? count( $context['linking']['weak_money_pages'] ) : 0;
    $link_opps    = isset( $links['items'] ) ? count( $links['items'] ) : 0;

    $cards = array(
        array( 'lbl' => 'Pages analysed',       'num' => $total ),
        array( 'lbl' => 'Money pages',          'num' => $by_type['money'] ),
        array( 'lbl' => 'Service pages',        'num' => $by_type['service'] ),
        array( 'lbl' => 'Location pages',       'num' => $by_type['location'] ),
        array( 'lbl' => 'Support pages',        'num' => $support_total ),
        array( 'lbl' => 'Orphan pages',         'num' => $orphan ),
        array( 'lbl' => 'Near-orphan pages',    'num' => $near_orphan ),
        array( 'lbl' => 'Weak money pages',     'num' => $weak_money ),
        array( 'lbl' => 'Link opportunities',   'num' => $link_opps ),
    );
    $html = tse_ai_report_section_heading( 'Export summary metrics', count( $cards ) );
    $html .= tse_ai_report_why( 'Deterministic site totals from the underlying export.' );
    $html .= '<div class="metrics">';
    foreach ( $cards as $c ) {
        $html .= '<div class="m"><div class="lbl">' . htmlspecialchars( $c['lbl'], ENT_QUOTES, 'UTF-8' ) . '</div>'
              . '<div class="num">' . (int) $c['num'] . '</div></div>';
    }
    return $html . '</div>';
}

/* -------------------------------------------------------------------------
 * Suggested Priority Order — Fix first / next / later
 * ---------------------------------------------------------------------- */
function tse_ai_report_priority_order_block( $rec_items, $gap_items, $page_index ) {
    $all = array_merge(
        isset( $rec_items['items'] ) ? $rec_items['items'] : array(),
        isset( $gap_items['items'] ) ? $gap_items['items'] : array()
    );
    $sorted = tse_ai_report_sort_by_priority( $all );

    $first = array(); $next = array(); $later = array();
    foreach ( $sorted as $it ) {
        $p = strtolower( isset( $it['priority'] ) ? (string) $it['priority'] : '' );
        if ( 'high' === $p && count( $first ) < 4 )       $first[] = $it;
        elseif ( 'medium' === $p && count( $next ) < 4 )  $next[]  = $it;
        elseif ( 'low' === $p && count( $later ) < 4 )    $later[] = $it;
        if ( count( $first ) >= 4 && count( $next ) >= 4 && count( $later ) >= 4 ) break;
    }

    $render_col = function( $items, $title, $class, $pill ) use ( $page_index ) {
        $h = '<div class="col ' . $class . '"><h4>' . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' )
           . '<span class="pill">' . htmlspecialchars( $pill, ENT_QUOTES, 'UTF-8' ) . '</span></h4>';
        if ( empty( $items ) ) {
            $h .= '<p class="empty-mini">Nothing flagged at this level.</p>';
        } else {
            $h .= '<ol>';
            foreach ( $items as $it ) {
                $issue = htmlspecialchars( (string) ( isset( $it['issue'] ) ? $it['issue'] : '' ), ENT_QUOTES, 'UTF-8' );
                $target = '';
                if ( ! empty( $it['affected_pages'] ) && is_array( $it['affected_pages'] ) ) {
                    $hit = tse_ai_report_lookup_page( $it['affected_pages'][0], $page_index );
                    if ( $hit && ! empty( $hit['title'] ) ) {
                        $target = $hit['title'];
                    } else {
                        $target = $hit && ! empty( $hit['path'] ) ? $hit['path'] : $it['affected_pages'][0];
                    }
                }
                $h .= '<li>' . $issue
                    . ( $target ? ' <span class="target">— ' . htmlspecialchars( $target, ENT_QUOTES, 'UTF-8' ) . '</span>' : '' )
                    . '</li>';
            }
            $h .= '</ol>';
        }
        return $h . '</div>';
    };

    $html  = tse_ai_report_section_heading( 'Suggested priority order', count( $first ) + count( $next ) + count( $later ) );
    $html .= tse_ai_report_why( 'Work top-to-bottom. Fix first → highest blast radius, fix later → polish.' );
    $html .= '<div class="priority-order">'
           . $render_col( $first, 'Fix first',  'first', 'HIGH' )
           . $render_col( $next,  'Fix next',   'next',  'MEDIUM' )
           . $render_col( $later, 'Fix later',  'later', 'LOW' )
           . '</div>';
    return $html;
}

/* -------------------------------------------------------------------------
 * Executive Summary cards (unchanged from V2.6)
 * ---------------------------------------------------------------------- */
function tse_ai_report_executive_summary( $recs, $gaps, $context ) {
    $rec_items = isset( $recs['items'] ) ? $recs['items'] : array();
    $gap_items = isset( $gaps['items'] ) ? $gaps['items'] : array();

    $high = 0; $med = 0;
    foreach ( $rec_items as $it ) {
        $p = strtolower( isset( $it['priority'] ) ? (string) $it['priority'] : '' );
        if ( 'high'   === $p ) $high++;
        if ( 'medium' === $p ) $med++;
    }
    $near_orphans = isset( $context['linking']['near_orphan_pages'] ) ? count( $context['linking']['near_orphan_pages'] ) : 0;
    $weak_money   = isset( $context['linking']['weak_money_pages'] )  ? count( $context['linking']['weak_money_pages'] ) : 0;

    $cannibal = 0;
    foreach ( $gap_items as $g ) {
        $gt = strtolower( isset( $g['gap_type'] ) ? (string) $g['gap_type'] : '' );
        if ( 'cannibalisation' === $gt || 'cannibalization' === $gt || 'topic_overlap' === $gt ) $cannibal++;
    }
    if ( ! empty( $context['linking']['duplicate_meta_titles'] ) )       $cannibal += count( $context['linking']['duplicate_meta_titles'] );
    if ( ! empty( $context['linking']['duplicate_meta_descriptions'] ) ) $cannibal += count( $context['linking']['duplicate_meta_descriptions'] );

    $thin = 0;
    if ( ! empty( $context['pages'] ) ) {
        foreach ( $context['pages'] as $p ) {
            if ( isset( $p['issues'] ) && is_array( $p['issues'] ) && in_array( 'thin_content', $p['issues'], true ) ) $thin++;
        }
    }

    $cards = array(
        array( 'lbl' => 'High Priority Issues',   'num' => $high,         'cls' => 'h', 'sub' => 'From AI recommendations' ),
        array( 'lbl' => 'Medium Priority Issues', 'num' => $med,          'cls' => 'm', 'sub' => 'From AI recommendations' ),
        array( 'lbl' => 'Near-Orphan Pages',      'num' => $near_orphans, 'cls' => 'm', 'sub' => 'Pages with only 1 inbound link' ),
        array( 'lbl' => 'Weak Money Pages',       'num' => $weak_money,   'cls' => 'h', 'sub' => 'Below-median authority' ),
        array( 'lbl' => 'Cannibalisation Risks',  'num' => $cannibal,     'cls' => 'm', 'sub' => 'Duplicate / overlapping signals' ),
        array( 'lbl' => 'Thin Content Signals',   'num' => $thin,         'cls' => 'l', 'sub' => 'Pages under 300 words' ),
    );

    $html  = tse_ai_report_section_heading( 'Executive summary', count( $cards ) );
    $html .= tse_ai_report_why( 'A quick snapshot of where the site is bleeding authority right now.' );
    $html .= '<div class="exec">';
    foreach ( $cards as $c ) {
        $html .= '<div class="card ' . $c['cls'] . '">'
              . '<span class="lbl">' . htmlspecialchars( $c['lbl'], ENT_QUOTES, 'UTF-8' ) . '</span>'
              . '<span class="num">' . (int) $c['num'] . '</span>'
              . '<span class="sub">' . htmlspecialchars( $c['sub'], ENT_QUOTES, 'UTF-8' ) . '</span>'
              . '</div>';
    }
    return $html . '</div>';
}

/* -------------------------------------------------------------------------
 * Quick Wins
 * ---------------------------------------------------------------------- */
function tse_ai_report_quick_wins( $links, $context, $page_index ) {
    $wins = array();
    $link_items = isset( $links['items'] ) ? $links['items'] : array();
    $link_items = tse_ai_report_sort_by_priority( $link_items );
    $taken = 0;
    foreach ( $link_items as $it ) {
        if ( $taken >= 3 ) break;
        $src = isset( $it['source_url'] ) ? $it['source_url'] : '';
        $tgt = isset( $it['target_url'] ) ? $it['target_url'] : '';
        if ( ! $src || ! $tgt ) continue;
        $anchor = isset( $it['suggested_anchor'] ) ? $it['suggested_anchor'] : '';
        $wins[] = array(
            'title' => 'Add internal link' . ( $anchor ? ' (anchor: ' . $anchor . ')' : '' ),
            'desc'  => isset( $it['reason'] ) ? $it['reason'] : ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ),
            'pages' => array( $src, $tgt ),
        );
        $taken++;
    }
    if ( ! empty( $context['linking']['duplicate_meta_titles'] ) ) {
        foreach ( array_slice( $context['linking']['duplicate_meta_titles'], 0, 2 ) as $d ) {
            $wins[] = array(
                'title' => 'Fix duplicate meta titles',
                'desc'  => 'Multiple pages share the same meta title — rewrite each to be unique and intent-specific.',
                'pages' => isset( $d['urls'] ) ? $d['urls'] : array(),
            );
        }
    }
    if ( ! empty( $context['pages'] ) ) {
        $missing = array();
        foreach ( $context['pages'] as $p ) {
            $issues = isset( $p['issues'] ) && is_array( $p['issues'] ) ? $p['issues'] : array();
            if ( in_array( 'missing_meta_description', $issues, true )
              && in_array( ( isset( $p['strategic_type'] ) ? $p['strategic_type'] : '' ), array( 'money', 'service', 'location', 'product' ), true ) ) {
                $missing[] = $p['url'];
            }
            if ( count( $missing ) >= 5 ) break;
        }
        if ( ! empty( $missing ) ) {
            $wins[] = array(
                'title' => 'Write meta descriptions for high-value pages',
                'desc'  => 'These conversion-focused pages have no meta description — write one with a clear value proposition + call to action.',
                'pages' => $missing,
            );
        }
    }
    if ( ! empty( $context['pages'] ) ) {
        $noindex = array();
        foreach ( $context['pages'] as $p ) {
            $issues = isset( $p['issues'] ) && is_array( $p['issues'] ) ? $p['issues'] : array();
            $st     = isset( $p['strategic_type'] ) ? $p['strategic_type'] : 'other';
            $in_ct  = isset( $p['incoming_link_count'] ) ? (int) $p['incoming_link_count'] : 0;
            if ( 'other' === $st && in_array( 'thin_content', $issues, true ) && $in_ct <= 1 ) {
                $noindex[] = $p['url'];
            }
            if ( count( $noindex ) >= 5 ) break;
        }
        if ( ! empty( $noindex ) ) {
            $wins[] = array(
                'title' => 'Consider noindex on low-value utility pages',
                'desc'  => 'These pages are thin, non-strategic, and barely linked internally — noindexing them concentrates crawl + authority on pages that matter.',
                'pages' => $noindex,
            );
        }
    }

    $html  = tse_ai_report_section_heading( 'Quick wins', count( $wins ) );
    $html .= tse_ai_report_why( 'High-impact, low-effort actions you can ship this week.' );
    if ( empty( $wins ) ) return $html . '<div class="empty">No quick wins detected.</div>';
    $html .= '<div class="qw">';
    $n = 0;
    foreach ( $wins as $w ) {
        $n++;
        $html .= '<div class="qw-row">';
        $html .= '<span class="num-circle">' . $n . '</span>';
        $html .= '<div><div class="title">' . htmlspecialchars( $w['title'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        if ( ! empty( $w['desc'] ) ) $html .= '<div class="desc">' . htmlspecialchars( $w['desc'], ENT_QUOTES, 'UTF-8' ) . '</div>';
        if ( ! empty( $w['pages'] ) ) {
            $html .= '<div class="qw-pages">' . tse_ai_report_pages_cell( $w['pages'], $page_index ) . '</div>';
        }
        $html .= '</div></div>';
    }
    return $html . '</div>';
}

/* -------------------------------------------------------------------------
 * V2.10 — Unified issue track renderer + clickable summary cards
 * ---------------------------------------------------------------------- */

function tse_ai_report_issue_row( $iss, $page_index ) {
    $sev_class = in_array( $iss['severity'], array( 'high', 'medium', 'low' ), true ) ? $iss['severity'] : 'low';
    $h = '<div class="iss">';
    $h .= '<div class="meta">'
        . tse_ai_report_badge( strtoupper( $sev_class ), $sev_class )
        . tse_ai_report_confidence_badge( $iss['confidence'] )
        . '</div>';
    $h .= '<div class="body">';
    $h .= '<p class="title">' . htmlspecialchars( $iss['issue'] ?: ( $iss['recommendation'] ?: '—' ), ENT_QUOTES, 'UTF-8' ) . '</p>';
    if ( ! empty( $iss['recommendation'] ) ) {
        $h .= '<p class="rec">' . htmlspecialchars( $iss['recommendation'], ENT_QUOTES, 'UTF-8' ) . '</p>';
    }
    if ( ! empty( $iss['implementation_guidance'] ) ) {
        $h .= '<p class="guide">How to action: ' . htmlspecialchars( $iss['implementation_guidance'], ENT_QUOTES, 'UTF-8' ) . '</p>';
    }
    if ( ! empty( $iss['affected_pages'] ) ) {
        $h .= '<div class="pages">' . tse_ai_report_pages_cell( $iss['affected_pages'], $page_index ) . '</div>';
    }
    $h .= '</div>';
    $h .= '<div class="side">'
        . '<span class="group-pill">' . htmlspecialchars( $iss['group'], ENT_QUOTES, 'UTF-8' ) . '</span>'
        . '<span>Action: ' . ( 'developer_technical' === $iss['action_type'] ? 'Developer / Technical' : 'Content / Admin' ) . '</span>'
        . ( ! empty( $iss['intent_filter'] ) ? '<span>Intent: ' . htmlspecialchars( implode( ', ', $iss['intent_filter'] ), ENT_QUOTES, 'UTF-8' ) . '</span>' : '' )
        . '</div>';
    return $h . '</div>';
}

function tse_ai_report_unified_tracks( $issues, $page_index ) {
    if ( empty( $issues ) ) return '';
    $split   = tse_issues_split_tracks( $issues );
    $content = $split['content_admin'];
    $dev     = $split['developer_technical'];

    $render_track = function( $title, $items, $class, $description ) use ( $page_index ) {
        $h  = '<div class="track">';
        $h .= '<h3><span class="track-label ' . $class . '">' . ( 'content' === $class ? 'CONTENT / ADMIN' : 'DEVELOPER / TECHNICAL' ) . '</span>'
            . htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' ) . ' <span style="color:#6b7280;font-weight:500;font-size:13px">(' . count( $items ) . ')</span></h3>';
        $h .= '<p class="subtitle">' . htmlspecialchars( $description, ENT_QUOTES, 'UTF-8' ) . '</p>';
        if ( empty( $items ) ) {
            $h .= '<div class="empty">Nothing in this track.</div>';
        } else {
            // Sub-group by issue group so the layout mirrors the unified taxonomy.
            $by_group = array();
            foreach ( $items as $i ) $by_group[ $i['group'] ][] = $i;
            $order = array( 'Strategy', 'Linking', 'Metadata', 'Cannibalisation', 'Thin Content', 'Architecture', 'Authority', 'Other' );
            foreach ( $order as $g ) {
                if ( empty( $by_group[ $g ] ) ) continue;
                $h .= '<h3 class="subsection" style="margin-top:16px">' . htmlspecialchars( $g, ENT_QUOTES, 'UTF-8' )
                    . '<span class="count">' . count( $by_group[ $g ] ) . '</span></h3>';
                foreach ( $by_group[ $g ] as $iss ) {
                    $h .= tse_ai_report_issue_row( $iss, $page_index );
                }
            }
        }
        return $h . '</div>';
    };

    $h  = tse_ai_report_section_heading( 'Actions to take', count( $issues ) );
    $h .= tse_ai_report_why( 'A single deduplicated list, split by who can action each item. Items affecting non-SEO pages (legal / utility / conversion / template / noindex) have already been suppressed.' );
    $h .= $render_track( 'Content / Admin track', $content, 'content', 'Tasks a content editor or marketing admin can complete without developer help.' );
    $h .= $render_track( 'Developer / Technical track', $dev, 'dev', 'Tasks requiring schema / template / redirect / sitemap changes — coordinate with a developer.' );
    return $h;
}

/* -------------------------------------------------------------------------
 * Clickable summary card — replaces the V2.6 static exec-summary cards.
 * Each card lists the affected URLs in a collapsible <details> body.
 * ---------------------------------------------------------------------- */

function tse_ai_report_clickable_metric( $label, $count, $tone, $sub, $url_list, $page_index ) {
    $cls = in_array( $tone, array( 'h', 'm', 'l' ), true ) ? $tone : 'l';
    $h  = '<details class="metric-card ' . $cls . '"' . ( $count > 0 ? ' open' : '' ) . '>';
    $h .= '<summary>'
        . '<span class="lbl">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' ) . '</span>'
        . '<span class="num">' . (int) $count . '</span>'
        . '<span class="sub">' . htmlspecialchars( $sub, ENT_QUOTES, 'UTF-8' ) . '</span>'
        . '</summary>';
    $h .= '<div class="body"><div class="pl">';
    if ( $count <= 0 ) {
        $h .= '<em style="color:#9ca3af">Nothing flagged in this bucket.</em>';
    } elseif ( empty( $url_list ) ) {
        $h .= '<em style="color:#9ca3af">Affected pages not surfaced — see the relevant issue track below.</em>';
    } else {
        $h .= '<div class="pages">' . tse_ai_report_pages_cell( $url_list, $page_index ) . '</div>';
    }
    $h .= '</div></div></details>';
    return $h;
}

function tse_ai_report_executive_summary_v2( $issues, $context, $page_index ) {
    $high = 0; $med = 0; $high_pages = array(); $med_pages = array();
    foreach ( $issues as $i ) {
        if ( 'high'   === $i['severity'] ) { $high++; foreach ( $i['affected_pages'] as $u ) $high_pages[ $u ] = true; }
        if ( 'medium' === $i['severity'] ) { $med++;  foreach ( $i['affected_pages'] as $u ) $med_pages[ $u ]  = true; }
    }

    $near_orphans       = isset( $context['linking']['near_orphan_pages'] ) ? $context['linking']['near_orphan_pages'] : array();
    $weak_money         = isset( $context['linking']['weak_money_pages'] )  ? $context['linking']['weak_money_pages']  : array();
    $near_orphans_pages = array_filter( array_map( function( $p ) { return $p['url'] ?? ''; }, (array) $near_orphans ), 'strlen' );
    $weak_money_pages   = array_filter( array_map( function( $p ) { return $p['url'] ?? ''; }, (array) $weak_money ),   'strlen' );

    $cannibal_pages = array();
    if ( ! empty( $context['linking']['duplicate_meta_titles'] ) ) {
        foreach ( $context['linking']['duplicate_meta_titles'] as $d ) {
            foreach ( (array) ( $d['urls'] ?? array() ) as $u ) $cannibal_pages[ $u ] = true;
        }
    }
    $thin_pages = array();
    if ( ! empty( $context['pages'] ) ) {
        foreach ( $context['pages'] as $p ) {
            $iss = $p['issues'] ?? array();
            if ( is_array( $iss ) && in_array( 'thin_content', $iss, true ) ) $thin_pages[ $p['url'] ?? '' ] = true;
        }
    }

    $cards = array(
        array( 'High Priority Issues',  $high,                       'h', 'From the unified action list',  array_keys( $high_pages ) ),
        array( 'Medium Priority Issues',$med,                        'm', 'From the unified action list',  array_keys( $med_pages ) ),
        array( 'Near-Orphan Pages',     count( $near_orphans_pages ),'m', 'Pages with only 1 inbound link',array_values( $near_orphans_pages ) ),
        array( 'Weak Strategic Targets',count( $weak_money_pages ),  'h', 'Declared / inferred targets that are under-supported', array_values( $weak_money_pages ) ),
        array( 'Cannibalisation Risks', count( $cannibal_pages ),    'm', 'Duplicate / overlapping signals', array_keys( $cannibal_pages ) ),
        array( 'Thin Content Signals',  count( $thin_pages ),        'l', 'Pages under 300 words',          array_keys( $thin_pages ) ),
    );

    $h  = tse_ai_report_section_heading( 'Executive summary', count( $cards ) );
    $h .= tse_ai_report_why( 'Click any tile to expand the list of affected pages. Items already suppressed by intent / indexability are excluded.' );
    $h .= '<div class="exec">';
    foreach ( $cards as $c ) {
        [ $label, $count, $tone, $sub, $list ] = $c;
        $h .= tse_ai_report_clickable_metric( $label, $count, $tone, $sub, $list, $page_index );
    }
    return $h . '</div>';
}

/* -------------------------------------------------------------------------
 * Internal-link card renderer (V2.9.0 — implementation-style).
 * Each card reads like a Jira ticket: FROM / TO / Anchor / Reason.
 * ---------------------------------------------------------------------- */
function tse_ai_report_path_of( $url, $page_index ) {
    $hit = tse_ai_report_lookup_page( $url, $page_index );
    return $hit && ! empty( $hit['path'] ) ? $hit['path'] : $url;
}

function tse_ai_report_link_card( $it, $page_index ) {
    $pr     = tse_ai_report_priority_class( isset( $it['priority'] ) ? $it['priority'] : '' );
    $src    = isset( $it['source_url'] ) ? (string) $it['source_url'] : ( isset( $it['affected_pages'][0] ) ? $it['affected_pages'][0] : '' );
    $tgt    = isset( $it['target_url'] ) ? (string) $it['target_url'] : ( isset( $it['affected_pages'][1] ) ? $it['affected_pages'][1] : '' );
    $anchor = (string) ( isset( $it['suggested_anchor'] ) ? $it['suggested_anchor'] : '' );
    $reason = (string) ( isset( $it['reason'] ) ? $it['reason'] : ( isset( $it['recommendation'] ) ? $it['recommendation'] : '' ) );
    $impact = tse_ai_report_estimated_impact( array_merge( $it, array( 'affected_pages' => array_filter( array( $src, $tgt ) ) ) ), $page_index );

    $src_path = $src ? tse_ai_report_path_of( $src, $page_index ) : '';
    $tgt_path = $tgt ? tse_ai_report_path_of( $tgt, $page_index ) : '';
    $src_hit  = $src ? tse_ai_report_lookup_page( $src, $page_index ) : null;
    $tgt_hit  = $tgt ? tse_ai_report_lookup_page( $tgt, $page_index ) : null;

    $h = '<div class="link-card">';
    $h .= '<div class="badge-cell">'
        . tse_ai_report_badge( strtoupper( $pr ), $pr )
        . tse_ai_report_impact_badge( $impact )
        . tse_ai_report_confidence_badge( isset( $it['confidence_score'] ) ? $it['confidence_score'] : null )
        . '</div>';

    $h .= '<div>';
    $h .= '<p class="title">Add internal link</p>';

    // FROM (now: EDIT THIS PAGE)
    $h .= '<div class="field"><div class="lbl">Edit this page</div><div class="val path">';
    if ( $src ) {
        $title = $src_hit && ! empty( $src_hit['title'] ) ? ' <span style="color:#6b7280;font-size:12px;margin-left:6px">— ' . htmlspecialchars( $src_hit['title'], ENT_QUOTES, 'UTF-8' ) . '</span>' : '';
        $h .= '<a href="' . htmlspecialchars( $src, ENT_QUOTES, 'UTF-8' ) . '" target="_blank" rel="noopener">'
            . htmlspecialchars( $src_path, ENT_QUOTES, 'UTF-8' ) . '</a>' . $title;
    } else {
        $h .= '<span style="color:#9ca3af">—</span>';
    }
    $h .= '</div></div>';

    // TO (now: ADD LINK TO)
    $h .= '<div class="field"><div class="lbl">Add link to</div><div class="val path">';
    if ( $tgt ) {
        $title = $tgt_hit && ! empty( $tgt_hit['title'] ) ? ' <span style="color:#6b7280;font-size:12px;margin-left:6px">— ' . htmlspecialchars( $tgt_hit['title'], ENT_QUOTES, 'UTF-8' ) . '</span>' : '';
        $h .= '<a href="' . htmlspecialchars( $tgt, ENT_QUOTES, 'UTF-8' ) . '" target="_blank" rel="noopener">'
            . htmlspecialchars( $tgt_path, ENT_QUOTES, 'UTF-8' ) . '</a>' . $title;
    } else {
        $h .= '<span style="color:#9ca3af">—</span>';
    }
    $h .= '</div></div>';

    // Anchor
    $h .= '<div class="field"><div class="lbl">Suggested anchor</div><div class="val">';
    if ( '' !== $anchor ) {
        $h .= '<span class="anchor">' . htmlspecialchars( '"' . $anchor . '"', ENT_QUOTES, 'UTF-8' ) . '</span>';
    } else {
        $h .= '<span style="color:#9ca3af">—</span>';
    }
    $h .= '</div></div>';

    // Reason
    $h .= '<div class="field"><div class="lbl">Reason</div><div class="val reason">'
        . ( '' !== $reason ? htmlspecialchars( $reason, ENT_QUOTES, 'UTF-8' ) : '<span style="color:#9ca3af">—</span>' )
        . '</div></div>';

    $h .= '</div></div>';
    return $h;
}

/* -------------------------------------------------------------------------
 * Strategy vs reality block — renders deterministic mismatches from
 * strategy-mismatch.json when available.
 * ---------------------------------------------------------------------- */
function tse_ai_report_strategy_block( $context, $page_index ) {
    $mismatch = isset( $context['strategy']['mismatch'] ) ? $context['strategy']['mismatch'] : null;
    $buckets  = isset( $context['strategy']['buckets'] )  ? $context['strategy']['buckets']  : array();
    $declared_total = 0;
    foreach ( (array) $buckets as $list ) $declared_total += count( (array) $list );
    if ( ! $declared_total ) return '';

    $items  = isset( $mismatch['items'] ) ? $mismatch['items'] : array();
    $totals = isset( $mismatch['totals'] ) ? $mismatch['totals'] : array();

    $h  = tse_ai_report_section_heading( 'Strategy vs reality', count( $items ) );
    $h .= tse_ai_report_why( 'You declared a business strategy. Here is where the internal architecture currently diverges from it.' );

    $declared = (int) ( isset( $totals['declared_total'] ) ? $totals['declared_total'] : $declared_total );
    $resolved = (int) ( isset( $totals['declared_resolved'] ) ? $totals['declared_resolved'] : 0 );
    $unres    = (int) ( isset( $totals['declared_unresolved'] ) ? $totals['declared_unresolved'] : 0 );
    $h .= '<div class="strategy-note">'
        . '<strong>' . $declared . '</strong> URL' . ( 1 === $declared ? '' : 's' ) . ' declared across 6 buckets, '
        . '<strong>' . $resolved . '</strong> matched to live pages, '
        . '<strong>' . $unres    . '</strong> not found in this export.'
        . '</div>';

    if ( empty( $items ) ) {
        $h .= '<div class="empty">Declared strategy matches the observed architecture — no mismatches detected.</div>';
        return $h;
    }
    return $h . tse_ai_report_render_rec_table( tse_ai_report_sort_by_priority( $items ), $page_index );
}

/* -------------------------------------------------------------------------
 * ai-report.html
 * ---------------------------------------------------------------------- */
function tse_ai_report_main( $meta, $recs, $gaps, $links, $context, $page_index ) {
    $html  = tse_ai_report_header( 'AI Site Analysis Report', $meta );
    $html .= tse_ai_report_render_error( $recs );

    // V2.10 — build unified issue list (suppress non-SEO, dedupe across prompts).
    $lookup = function_exists( 'tse_issues_build_page_lookup' )
        ? tse_issues_build_page_lookup( isset( $context['pages'] ) ? $context['pages'] : array() )
        : array();
    $strategy_items = isset( $context['strategy']['mismatch']['items'] )
        ? (array) $context['strategy']['mismatch']['items']
        : array();
    $raw_by_source = array(
        'recommendations' => isset( $recs['items'] ) ? $recs['items'] : array(),
        'link_opps'       => isset( $links['items'] ) ? $links['items'] : array(),
        'content_gaps'    => isset( $gaps['items'] )  ? $gaps['items']  : array(),
        'strategy'        => $strategy_items,
    );
    $issues = function_exists( 'tse_issues_normalise' )
        ? tse_issues_normalise( $raw_by_source, $lookup )
        : array();

    $html .= tse_ai_report_export_metrics( $context, $links );
    $html .= tse_ai_report_executive_summary_v2( $issues, $context, $page_index );
    $html .= tse_ai_report_strategy_block( $context, $page_index );
    $html .= tse_ai_report_unified_tracks( $issues, $page_index );

    // Quick wins remain useful as a 3-card "what to ship this week" cap.
    $html .= tse_ai_report_quick_wins( $links, $context, $page_index );

    return $html . tse_ai_report_footer();
}

/* -------------------------------------------------------------------------
 * internal-link-report.html
 * ---------------------------------------------------------------------- */
function tse_ai_report_links( $meta, $links, $page_index ) {
    $html = tse_ai_report_header( 'Internal-Link Opportunities (LLM)', $meta );
    $html .= tse_ai_report_render_error( $links );

    $items = tse_ai_report_sort_by_priority( isset( $links['items'] ) ? $links['items'] : array() );
    $html .= tse_ai_report_section_heading( 'Refined link opportunities', count( $items ) );
    $html .= tse_ai_report_why( 'Each card is an implementation-ready instruction. Copy it into your task tracker, edit the source page, paste the anchor.' );
    if ( empty( $items ) ) return $html . '<div class="empty">No internal-link opportunities were returned.</div>' . tse_ai_report_footer();

    foreach ( $items as $it ) {
        $html .= tse_ai_report_link_card( $it, $page_index );
    }
    return $html . tse_ai_report_footer();
}

/* -------------------------------------------------------------------------
 * cluster-report.html
 * ---------------------------------------------------------------------- */
function tse_ai_report_clusters( $meta, $clusters, $page_index ) {
    $html = tse_ai_report_header( 'Cluster Analysis (LLM)', $meta );
    $html .= tse_ai_report_render_error( $clusters );

    $items = isset( $clusters['items'] ) ? $clusters['items'] : array();
    $by_cluster = array();
    foreach ( $items as $it ) {
        $cid = isset( $it['cluster_id'] ) ? (int) $it['cluster_id'] : -1;
        $by_cluster[ $cid ][] = $it;
    }
    ksort( $by_cluster );

    $html .= tse_ai_report_section_heading( 'Findings', count( $items ) );
    $html .= tse_ai_report_why( 'Isolated clusters miss out on authority distribution from the main site graph.' );
    if ( empty( $items ) ) return $html . '<div class="empty">No cluster findings were returned.</div>' . tse_ai_report_footer();

    foreach ( $by_cluster as $cid => $cluster_items ) {
        $cluster_items = tse_ai_report_sort_by_priority( $cluster_items );
        $label = $cid >= 0 ? ( 'Cluster #' . $cid ) : 'Unclustered findings';
        $html .= '<h2 class="section" style="margin-top:24px">' . htmlspecialchars( $label, ENT_QUOTES, 'UTF-8' )
              . '<span class="count">' . count( $cluster_items ) . '</span></h2>';
        $html .= tse_ai_report_render_rec_table( $cluster_items, $page_index );
    }

    return $html . tse_ai_report_footer();
}
