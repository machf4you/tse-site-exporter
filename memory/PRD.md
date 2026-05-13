# PRD — TSE Site Exporter (WordPress Plugin)

## Original Problem Statement
V1: Tools page with one button "Export Site Data" that exports all public pages, posts, products and CPTs into a structured JSON file, compressed as ZIP.

V2: Upgrade from raw content export → AI-ready structured website intelligence export.
- Core page data, SEO (Yoast / Rank Math), content structure (H1/H2/H3 hierarchy, FAQs, plain text, shortcodes-removed, elementor-clean-text, word count).
- Internal linking with source/target post type + target classification + anchor-text frequency.
- External links with rel.
- Media (featured + inline images, ALT, filenames).
- CRO detection (CTAs, phones, emails, forms, trust signals, testimonials, FAQ sections).
- Elementor structured interpretation (no raw dump).
- Schema (JSON-LD) extraction.
- Site hierarchy file (homepage → money → support → articles).
- Optional slice files, optional live-URL fetch, optional broken-link check.

## Architecture (V2)
- `tse-site-exporter.php` — bootstrap, admin menu (Tools → TSE Site Exporter), form UI with Mode + toggles, `admin_post_*` handler that runs the exporter and streams the ZIP.
- `includes/exporter.php` — pure data extraction:
  - Target post types (`public` minus `attachment`).
  - Per-post `PageRecord` builder; renders content via `apply_filters('the_content', …)` once, DOM parses it.
  - Headings (H1 + H2 list + H3 paired with parent H2 by document order).
  - FAQs: FAQPage JSON-LD first, else heuristic H?/answer pairing.
  - Internal/external link extractor with rel, anchor, is_self, absolute URL resolution.
  - Inline + featured image extraction.
  - JSON-LD `<script type=application/ld+json>` block extractor (handles `@graph` + arrays).
  - SEO meta auto-detect Rank Math first, fall back to Yoast (title, desc, focus keywords, canonical, robots noindex, OG title/desc/image).
  - CRO heuristics: anchor/button regex CTA detection, phone+email regex, form-plugin shortcode signatures + Elementor form widget + native `<form>`, trust-keyword list, testimonial/review markup heuristics, FAQ section detection.
  - Elementor walker: recursive over `_elementor_data`, per-widget mapper for `heading / text-editor / button / image / icon-box / image-box / icon-list / toggle / accordion / form / shortcode / video / testimonial[-carousel]`, unknown widgets fall through to a string-harvester that produces a `text` summary; produces `widget_counts` and an overall `elementor_clean_text` string for AI ingestion.
  - Classifier: `homepage` (front page) → `article` (post) → `money` (product, or commercial slug/title keywords) → `support` (about/faq/policy/etc.) → `other`.
  - Internal link enrichment: every internal link gets `source_post_type`, `source_classification`, `target_post_type`, `target_classification`, `target_id`.
  - Bundle assembly: `manifest.json`, `full-export.json`, plus optional slices (`seo-data`, `internal-links` w/ `anchor_text_frequency`, `external-links`, `cro-analysis`, `schema`, `elementor-structure`, `hierarchy`, `orphans`).
- `includes/postprocess.php` — site-wide passes:
  - Anchor-text frequency map (normalised lowercase, sorted desc).
  - Orphan detection (zero incoming internal links, excluding self).
  - Hierarchy file (homepage / money_pages / support_pages / articles / other) with counts + entries.
  - Optional broken-link checker: HEAD with GET fallback, per-URL cached within a single run.

## User Personas
- **Site owner / agency** preparing a site for AI audit, internal-link analysis, CRO review, or replication/migration.
- **AI pipeline operator** ingesting site intelligence into RAG / fine-tuning workflows.

## Core Requirements (static)
1. Admin page under Tools with one primary button.
2. Export ZIP of structured JSON files.
3. Every published post/page/product/CPT becomes a canonical PageRecord.
4. Only `publish` status; `attachment` excluded.
5. Cross-referenced internal links + anchor frequency.
6. Page classification + hierarchy file.
7. Interpreted Elementor structure (never raw dump).
8. Capability-gated (`manage_options`), nonce-protected.

## What's Implemented (2026-01)
- V1: raw content export (Tools page, JSON-in-ZIP).
- V2.0.0:
  - Admin form with Mode (Quick ≤500 / Full) + toggles (live fetch, broken-link check, slice files).
  - PageRecord with: id/url/slug/post_type/status/dates/parent/template/author/classification.
  - SEO block (Rank Math + Yoast, focus keywords, canonical, robots, OG).
  - Content block (H1, H2[], H3 with parent_h2, FAQs, word_count, plain_text, shortcodes_removed, elementor_clean_text).
  - Links block (internal/external/counts + self detection + enriched target metadata).
  - Media block (featured image record + de-duplicated inline images).
  - CRO block (CTAs, phones, emails, forms, trust_signals, testimonials, faq_section).
  - Elementor block (widget_counts + sections[] of interpreted widgets; unknown widget fallback).
  - Schema blocks (raw JSON-LD entries with `@graph` flattening).
  - Optional slice files including new `hierarchy.json` (counts + grouped entries) and `internal-links.json` with `anchor_text_frequency`.
  - Validated via 33-check PHP smoke harness.
- V2.1.0 — Structured-data audit upgrade:
  - New `includes/schema.php` (resilient JSON-LD extractor + per-block classifier + per-page summary + quality flags + site-wide rollup).
  - 4-pass JSON recovery (strict → HTML-entity-decode + CDATA-strip → strip JS comments → strip trailing commas). Unparseable blocks captured as `malformed` with error + 500-char preview.
  - `@graph`, top-level arrays, multiple `<script>` tags, and arbitrary attribute ordering all handled.
  - LocalBusiness subtype catalog (~120 types: Dentist, Plumber, Restaurant, etc.).
  - Deep-collect Review / AggregateRating / Question inside nested entities (e.g. Product → review[]).
  - PageRecord now has `schema = { raw_blocks, interpreted, malformed, summary, quality_flags }`.
  - Summary: schema_types_detected, faq_count, review_count, aggregate_rating, aggregate_rating_present, plus presence flags for Organization / LocalBusiness / WebSite / WebPage / Breadcrumb / Product / Article / Service.
  - Quality flags per page: malformed-schema-detected, no-schema-detected, money-page-missing-faq, money-page-missing-reviews, article-missing-article-schema, homepage-missing-organization, homepage-missing-localbusiness, page-missing-breadcrumb.
  - New `schema-rollup.json` slice: totals, types_distribution, site_level presence flags, issue lists per quality flag, malformed_pages, recommendations.
  - Live-URL fetch toggle is now ON by default (essential because most SEO/schema plugins inject JSON-LD into `wp_head`, outside the post content).
  - Validated via 35-check PHP smoke harness covering arrays, `@graph`, malformed recovery, LB subtypes, nested counts, all quality flags, full rollup.
- V2.1.1 — Extraction-quality round:
  - **SEO**: rewritten `tse_extract_seo` to take `$live_html` and use it as the authoritative source for title / description / canonical / og:* / robots. New helpers: `tse_seo_extract_from_html` (regex-based head parser, attribute-order-agnostic), `tse_seo_expand_template` (delegates to Yoast `wpseo_replace_vars` and Rank Math `Helper::replace_vars` if active; manual fallback expands `%title%`, `%%title%%`, `%sitename%`, `%sep%`, `%excerpt%`, etc.), `tse_seo_detect_source` (active-plugin + meta-sniffing for Rank Math / Yoast / AIOSEO). Robots noindex/nofollow parsed from live `<meta name=robots>` first.
  - **Headings**: `tse_extract_headings` now combines three ordered passes — rendered DOM, Elementor heading widgets (newly emitted as a flat `headings` list by the walker), and live HTML restricted to `<main>`/`<article>` so theme chrome (header/footer/nav) is excluded. Single shared `seen_h2 / seen_h3` dedupe state keyed by normalised text + parent_h2.
  - **Elementor cleanup**: `tse_parse_elementor` dedupes consecutive identical chunks in `clean_text`. `tse_collect_strings` (fallback for unknown widgets) now filters out URLs, hex colours, CSS classes (`elementor-*`, `eicon-*`, `fa fas`, `wp-*`, `e-*`, `et-*`, `jet-*`, `hfe-*`), pure numbers/dimensions, and strings with no letter characters.
  - **Plain text**: new `tse_normalize_text` collapses whitespace and dedupes consecutive identical sentences. On Elementor pages with thin rendered output, `plain_text` falls back to `elementor_clean_text`.
  - Validated via 30-check quality smoke harness (live-HTML SEO, template expansion for RankMath/Yoast/AIOSEO, robots parsing from live + meta, Elementor-only H1, DOM+Elementor heading merge, `<main>` scoping, clean_text dedupe, collect_strings filter, normalize_text dedupe).
- V2.1.2 — Content-structure quality round:
  - Elementor Pro theme builder widgets (`theme-post-title`, `theme-page-title`, `theme-archive-title`) now have dedicated mappers that emit as `h1` headings; fall back to the post title supplied via new `tse_parse_elementor( $raw, $post_title )` argument when the widget setting is empty.
  - Pattern-based heading detection in `default:` branch — any unknown widget exposing `header_size + title` is now treated as a heading. Catches Essential Addons, JetElements, Crocoblock, etc.
  - New `tse_html_to_text` helper inserts word boundaries at block-level tags (`p`, `div`, `h1-h6`, `li`, `td/th/tr`, `section`, `article`, `header/footer/nav`, `aside`, `main`, `blockquote`, `figure`, `figcaption`, `details/summary`, `dt/dd`, `br`) before `wp_strip_all_tags`. Fixes `<h3>Fast</h3><p>Reliable</p>` collapsing into `FastReliable`.
  - Block-aware text conversion applied to `text-editor`, `theme-post-content`, `theme-post-excerpt`, `icon-box` description, toggle/accordion `tab_content`, the page-level `plain_text` and `shortcodes_removed`.
  - `clean_text` chunks are joined with sentence punctuation (`. ` appended where missing), so the joined text reads as proper sentences for AI ingestion.
  - Heading source priority is now `is_elementor` aware: Elementor → DOM → live HTML when Elementor, otherwise DOM → Elementor → live HTML. Visual order matches the rendered page.
  - Structural / chrome widgets (`breadcrumbs`, `nav-menu`, `theme-nav-menu`, `post-info`, `sidebar`, `spacer`, `divider`, `google_maps`, `social-icons`) now emit as `structural` and skip the noisy string-harvester fallback — `clean_text` stays clean.
  - `theme-site-logo` correctly typed as `image`, not `button`.
  - Validated via 15-check content-structure smoke + 7-check regression: `tse_html_to_text` word boundaries, theme-post-title H1 fallback, addon heading pattern detection, sentence-punctuation join, icon-box / text-editor paragraph splitting, Elementor-primary heading order on Elementor pages, DOM-primary on non-Elementor, structural-widget exclusion, consecutive-dedupe preserved, theme-site-logo typing.
- Combined test count V2.1.2: 33 (V2.0) + 35 (V2.1 schema) + 30 (V2.1.1 quality) + 15 (V2.1.2 content) + 7 (V2.1.2 regression) = **120/120**.

## Validation
- `php -l` clean on all PHP files.
- Pure-PHP smoke harness (no WP runtime needed): 33/33 passing.
- Full WordPress end-to-end run requires a real WP install — user responsibility.

## Backlog / Future
- P1: Background mode (Action Scheduler) for very large sites (>5k posts).
- P1: Per-post-type include/exclude UI.
- P2: NDJSON / CSV slice formats.
- P2: WP-CLI command (`wp tse-export`).
- P2: Cumulative export cache to avoid re-rendering unchanged posts.
- P3: Multisite "export network" sweep.

## Next Action Items
- User installs `/app/tse-site-exporter.zip` on a real WordPress site and verifies output against expected content.
- (Optional) Move broken-link / live-fetch heavy paths to background job in V2.1.
