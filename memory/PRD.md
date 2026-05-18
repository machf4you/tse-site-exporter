# TSE Site Exporter — Product Requirements

## Original Problem Statement
Build a WordPress plugin "TSE Site Exporter" that produces an AI-ready structured website intelligence JSON package: SEO, content hierarchy, links, media, CRO heuristics, Elementor structure, schema, relationship graph, and weighted internal-authority intelligence.

## Architecture
- WordPress plugin (PHP 8.x, native WP APIs only, no external services).
- Modules under `tse-site-exporter/includes/`:
  - `exporter.php` — orchestration, extraction, bundle assembly.
  - `postprocess.php` — hierarchy, anchor frequency, orphans, broken-link check.
  - `schema.php` — JSON-LD extraction & rollup.
  - `relationships.php` — directed internal-link graph + per-page metrics.
  - `authority.php` — weighted edges, PageRank-like authority, strategic classification, clusters, intelligence (V2.3.0).
- Output: ZIP of JSON files (manifest + full export + slices).

## Implemented (Changelog)

### V1.0.0 — Raw content export.
### V2.0.0 — AI-ready structured export (SEO, content, CRO, links, Elementor parsing).
### V2.1.0–2.1.3 — Schema engine (JSON-LD parsing, live HTML), SEO/H1 stabilisation, heading dedupe, content cleanup.
### V2.2.0 — Internal Link Relationship Engine: `internal-link-graph.json`, `orphan-pages.json`, `weak-pages.json`, `relationship-summary.json`. Per-page `relationships` block injected into each PageRecord.

### V2.2.0 hotfix (2026-02)
- Fixed `ArgumentCountError` in `tse_exporter_run` — relationships engine was added but never called/passed through. Wired up + injected per-page metrics into PageRecord.

### V2.3.0 — Weighted Internal Linking Engine (2026-02)
- `authority.php` module.
- Strategic page classifier → money / support / article / service / location / product / category / homepage / other (URL patterns + post-type + schema + CRO + FAQ signals).
- Weighted edge graph: descriptive anchor bonus, high-value-source bonus, generic-anchor penalty, nofollow x0.2.
- PageRank-like internal authority (damping 0.85, 30 iters, no dangling-mass redistribution → isolated pages stay at base).
- Composite scores per page (all 0..100): internal_authority_score, relationship_strength_score, contextual_support_score, incoming_link_quality_score.
- Cluster detection via union-find on undirected graph → main vs isolated clusters.
- Intelligence flags: overlinked (>=p95 incoming AND >=10), under-supported important (strategic ∈ money/service/location/product/category AND authority < median), high-outgoing-weak-incoming.
- New bundle files: `authority-map.json`, `weighted-link-graph.json`, `strategic-pages.json`, `cluster-signals.json`, `intelligence-flags.json`.
- Per-PageRecord `authority` block.

### V2.4.0 — AI Analysis Layer (2026-02)
- `ai_summary.php` module — backend-only, no LLM/API calls. Builds compact AI-ready datasets:
  - `ai-site-summary.json` — totals, distributions, top authorities/hubs, issue counts, coverage.
  - `ai-page-summaries.json` — slim per-page records (no Elementor, no raw text, no schema dump). Includes URL, title, meta_title/description, H1, H2 list (top 10), H3 count, word_count, strategic_type, all 4 authority scores, link counts, cluster_id, is_isolated, top inbound/outbound anchors/sources, issue flags.
  - `ai-linking-summary.json` — weak money pages, orphan + near-orphan pages, under-supported clusters, duplicate metadata, linking opportunities (suggested source → target candidates with rationale).
  - `ai-cluster-summary.json` — main vs isolated clusters with recommended bridge sources from the main graph.
- Per-page deterministic issue flags: missing_meta_title/description, short_meta_*, weak_h1, thin_content, no_incoming_links, near_orphan, no_outgoing_internal_links, low_authority_for_<type>_page, generic_anchors_only_inbound.
- Token-economical: avg ~850 bytes per page summary on the smoke fixture.

### V2.5.0 — AI Analysis Execution Layer (2026-02)
- Modular PHP provider abstraction in `ai_provider.php` — `TSE_AI_Provider_Base` + three concrete providers:
  - OpenAI → `POST /v1/chat/completions` with `response_format: json_object`. Default model `gpt-5.2`.
  - Anthropic → `POST /v1/messages` with `anthropic-version: 2023-06-01`. Default model `claude-sonnet-4-5`.
  - Gemini → `POST /v1beta/models/{model}:generateContent` with `response_mime_type: application/json`. Default model `gemini-3-pro`.
- Settings (`ai_settings.php`): keys + models resolved constant-first, then `wp_options.tse_ai_settings`. UI fields are password-masked; PHP constants override.
- Runner (`ai_runner.php`) consumes the V2.4 ai-*.json files (no Elementor / no raw text sent to LLM) and dispatches 4 targeted prompts. Each output adheres to `{items:[{priority,issue,affected_pages,recommendation,confidence_score,...}]}`. Hard caps + "no prose" directives in every system prompt.
- New admin page section "AI Analysis (V2.5)" with provider/key/model fields + `Run AI Analysis` button. Two new handlers (`admin_post_tse_site_exporter_ai_save`, `admin_post_tse_site_exporter_ai_run`). Streams a ZIP of structured analysis JSON files.
- Error handling: HTTP non-2xx + JSON parse failures bubble up as `WP_Error` and are wrapped into `{status:"error", error:"...", items:[]}` so the analysis ZIP is always produced.

### V2.6.0 / V2.7.0 — HTML report readability pass (2026-02 → 2026-04)
- Wider layout (~1600px), sticky table headers, collapsible affected-pages, recommendations grouped by type, estimated SEO impact column, priority order block, export summary strip.

### V2.8.0 — Operational dashboard (2026-05)
- `includes/dashboard.php` — lightweight in-admin operational layer (no React, no charts, no SaaS chrome).
- Persists every export and AI run in `wp_options.tse_site_exporter_runs` (capped at 50, auto-prunes old ZIPs from disk).
- Stops unlinking the produced ZIP so it can be re-served. Files live in `wp-content/uploads/tse-site-exporter/`.
- Admin page additions under Tools → TSE Site Exporter:
  - **Export / Analysis history** table — date/time, type, provider, model, mode, success/failure, ZIP download, Delete.
  - **Recent Reports** panels grouped by *Exports / AI Reports / Internal Link Reports / Cluster Reports / Raw JSON*.
  - **In-admin viewer** — HTML reports open inside an iframe panel (no manual ZIP browsing); JSON streams inline.
- New admin-post handlers: `tse_site_exporter_serve` (file inside ZIP, allow-listed against the run's stored manifest), `tse_site_exporter_download_zip`, `tse_site_exporter_delete_run`. All nonce + `manage_options` protected.
- Failure paths now also write a history entry so unsuccessful runs remain visible.
- Tested via `/app/smoke_dashboard.php` — 29/29 assertions pass (categorisation, history lifecycle, pruning at TSE_RUNS_MAX, URL helpers).

### V2.9.0 — Strategic SEO Configuration + implementation-style wording (2026-05)
- New `includes/strategy.php`: option-backed declaration of 6 buckets — Money / Support / Location / Priority / Primary Conversion / Protected URLs. Path-level normalisation (case, trailing slash, query / fragment) for matching against page records.
- Two new bundle files emitted by `tse_exporter_run` when slices are enabled:
  - `strategy-config.json` — traceable record of the user's declared strategy.
  - `strategy-mismatch.json` — deterministic findings (under-linked money pages, weak primary conversion inbound, role conflicts, protected-URL metadata clashes, etc.).
- AI runner now passes the `strategy` block into all 4 prompts and **system prompts were rewritten** for implementation-style wording:
  - Imperative verbs only (Add / Rewrite / Remove / Merge / Redirect / Set).
  - Plain English; banned jargon list enforced at prompt level (`PageRank`, `link equity`, `passes strong`, `topical authority signals`, `siloing`).
  - Internal-link items must include explicit `source_url`, `target_url`, `suggested_anchor` (2–5 words, derived from target title), and a one-sentence `reason`.
  - Protected URLs may not be recommended for redirect / merge / noindex — wording must target the other party in the conflict.
- HTML reports:
  - `internal-link-report.html` rebuilt around an implementation card layout (FROM / TO / Suggested anchor / Reason).
  - `ai-report.html` gains a new **Strategy vs reality** section that surfaces the deterministic mismatch items, with declared/resolved/unresolved counts.
- Admin UI: new "Strategic SEO Configuration" panel above the dashboard with 6 textareas; save handler `tse_site_exporter_strategy_save` (nonce + `manage_options`).
- Tested via `/app/smoke_strategy.php` (28/28) and `/app/smoke_report_v29.php` (14/14): normalisation, parser dedup, 7 mismatch rule types, banned-jargon scan, card-layout rendering, strategy-section gating.

### V2.10.0 — Page intent + indexability + unified issue model (2026-05)
- New `includes/page_intent.php`: URL-pattern + post_type classifier → `intent ∈ { seo, utility, legal, conversion, template, gallery }`; `indexability ∈ { index, noindex, unknown }` from Yoast (`_yoast_wpseo_meta-robots-noindex`) + Rank Math (`rank_math_robots`) postmeta + live `<meta name="robots">`; one-shot sitemap fetcher (`sitemap_index.xml` → `wp-sitemap.xml`) caches the URL set so every record gets `excluded_from_sitemap` (nullable). All three dimensions ride through to `ai-page-summaries.json`.
- Strategy buckets reframed for time-bound campaigns. 9 buckets: Active Strategic Targets / Current SEO Targets / Growth Targets / Campaign Pages / Geo-Location Targets / Priority URLs / Primary Conversion Pages / Support Pages / Protected URLs. Auto-migration on first read: `money_pages → active_strategic_targets`, `location_pages → geo_location_targets`. Mismatch wording updated.
- New `includes/issue_normaliser.php`: unified schema `{ id, group, severity, action_type, intent_filter, affected_pages, recommendation, implementation_guidance, confidence, source }`. Groups: Metadata / Linking / Cannibalisation / Thin Content / Architecture / Authority / Strategy / Other. Three suppression rules (linking on non-SEO → drop, metadata/thin where ALL pages are non-SEO → drop). Deduper merges items emitted by multiple prompts. Action classifier tags each item `content_admin` or `developer_technical`.
- AI runner prompts rewritten: banned jargon list extended (`crawl prominence`, `internal equity`, `passes authority`); each item must declare `action_type` + `implementation_guidance`; non-SEO / noindex / sitemap-excluded pages explicitly excluded; new bucket vocabulary surfaced to all 4 prompts.
- Report renderer:
  - Card labels reworded — **FROM → "Edit this page"**, **TO → "Add link to"**.
  - New two-track section ("Content / Admin track" + "Developer / Technical track"), grouped by issue group.
  - Clickable `<details>` Executive Summary cards (High / Medium / Near-Orphan / Weak Strategic Targets / Cannibalisation Risks / Thin Content) expand inline to the affected URL list.
  - Old per-prompt "Prioritised recommendations" + "Content gap signals" tables removed in favour of the unified tracks.
- Tested via `smoke_v210.php` (51/51): intent classifier, indexability extractor, sitemap fetcher + URL set, strategy migration, V2.10 mismatch wording, normaliser suppression / dedupe / action_type, two-track splitter.
- All earlier suites green: `smoke_strategy.php` (28/28 — updated wording), `smoke_dashboard.php` (29/29), `smoke_report_v29.php` (14/14 — labels updated), `smoke_magento.php` (70/70 — no regression).


## Backlog / Roadmap
- **P1** Local SEO analysis — NAP consistency, LocalBusiness completeness, geo-signal scoring (WordPress side).
- **P2** Website replication / asset deployment workflows.
- **P2** Cheaper-tier model presets (GPT-4o-mini, Claude Haiku 4.5, Gemini 3 Flash) + per-prompt model overrides.
- **P2** Optional dashboard / chat interface.

---

# TSE Magento Exporter — Separate Product

A Magento 2 module that mirrors the WordPress exporter's intent for ecommerce stores. Same backend-only philosophy, same structured-JSON output, but built around the Magento 2 multi-store hierarchy (websites → groups → views).

## Implemented (V1.0.0, 2026-02)
- 7 PHP namespaces under `TSE\MagentoExporter\Model\`:
  - `StoreContextResolver` — websites/groups/store-views via `WebsiteRepositoryInterface` / `GroupRepositoryInterface` / `StoreRepositoryInterface`.
  - `CategoryExtractor` — normalised categories with path, children, store-scoped fields.
  - `ProductExtractor` — paginated (200/page), stock-aware, attribute-aware, image-aware. Carries `website_ids` + `store_ids`.
  - `CmsPageExtractor` — store-scoped URLs.
  - `RelationshipBuilder` — product → product edges (related/upsell/crosssell), category parent → child graph, product → category edges.
  - `CrossStoreMapper` — shared SKUs across storefronts, diverging categories, cross-store dangling references.
  - `Exporter` — orchestrator. Uses `Magento\Store\Model\App\Emulation` per store view so URLs/metadata/status reflect that store.
  - `ZipBuilder` — bundles to a single ZIP with per-store sub-directories.
- Admin page at System → Tools → TSE Magento Exporter (ACL-protected) with one "Download" button.
- Multi-store-aware bundle for HF / CBS / MT etc:
  ```
  manifest.json, stores.json, product-store-map.json, category-store-diff.json,
  relationship-cross-store-flags.json,
  stores/<code>/{products, categories, cms-pages, product-relationships, category-graph, product-category-edges}.json
  ```
- Tested via `/app/smoke_magento.php` — 54/54 assertions pass with stubbed Magento interfaces (hierarchy, per-store divergence, shared SKU detection, cross-store relationship dangling, emulation lifecycle, ZIP packaging).

## Magento Roadmap
- **V2** AI-ready summary slices (per-store + cross-store roll-ups), mirroring the WordPress V2.4 layer.
- **V2** AI analysis layer (provider abstraction, recommendations, internal linking, cannibalisation — reuse WordPress patterns).
- **V3** Static HTML reports identical in shape to the WordPress V2.7 reports.

## Testing
- Manual PHP CLI smoke tests under `/app/smoke_*.php` (WP function stubs + assertions). Run with `php /app/smoke_authority.php` etc.
- Latest passing: `smoke_run_fix.php` (V2.2 regression), `smoke_authority.php` (V2.3 full).
