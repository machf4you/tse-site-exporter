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
  - Validated via 33-check PHP smoke harness (URL normalisation, DOM extraction, classifier, full Elementor walker incl. unknown fallback, anchor normalisation).
- Distributable: `/app/tse-site-exporter.zip`.

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
