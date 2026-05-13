# TSE Site Exporter — V2

A WordPress plugin that exports **AI-ready structured website intelligence** as a single downloadable ZIP of JSON files. Not a raw WordPress dump — every page is reduced to a canonical record covering SEO, content hierarchy, FAQs, links (with cross-references), media, CRO signals, schema, and interpreted Elementor structure. Includes a site-wide hierarchy (homepage / money / support / articles), anchor-text frequency, orphan detection, and an optional broken-link check.

## What it produces

A ZIP containing:

```
manifest.json                # site meta + which files are inside + options used
full-export.json             # canonical PageRecord[] (all the per-page intelligence)
seo-data.json                # slim per-URL SEO slice
internal-links.json          # internal-link edges + global anchor_text_frequency
external-links.json          # external-link edges
cro-analysis.json            # CRO signals per URL
schema.json                  # extracted JSON-LD blocks
elementor-structure.json     # interpreted Elementor widget tree per page
hierarchy.json               # homepage → money → support → articles → other
orphans.json                 # orphan pages + broken internal links
```

### `PageRecord` shape

```json
{
  "id": 123,
  "url": "https://site.com/services/seo/",
  "slug": "seo",
  "post_type": "page",
  "status": "publish",
  "published_at": "2025-04-12T08:00:00+00:00",
  "modified_at":  "2025-12-30T11:15:00+00:00",
  "parent_id": 45,
  "template":  "page-templates/landing.php",
  "author":    { "id": 2, "name": "Jane Doe" },
  "classification": "money",

  "seo": {
    "source": "rank_math",
    "title": "...", "description": "...",
    "focus_keywords": ["..."],
    "canonical": "...",
    "robots": { "index": true, "follow": true },
    "og": { "title": "...", "description": "...", "image": "..." },
    "schema_types": []
  },

  "content": {
    "h1": "...", "h2": ["..."],
    "h3": [{ "parent_h2": "...", "text": "..." }],
    "faqs": [{ "q": "...", "a": "..." }],
    "word_count": 1234,
    "plain_text": "...",
    "shortcodes_removed": "...",
    "elementor_clean_text": "..."
  },

  "links": {
    "internal": [{
      "url": "...", "anchor": "...", "rel": [],
      "is_self": false,
      "source_post_type": "page", "source_classification": "money",
      "target_post_type": "page", "target_classification": "homepage",
      "target_id": 1
    }],
    "external": [{ "url": "...", "anchor": "...", "rel": ["nofollow"] }],
    "counts":   { "internal": 14, "external": 3, "self": 1 }
  },

  "media": {
    "featured": { "id": 99, "url": "...", "alt": "...", "filename": "..." },
    "images":   [{ "url": "...", "alt": "...", "filename": "..." }]
  },

  "cro": {
    "ctas":         [{ "text": "Book a Call", "type": "button", "evidence": "elementor-button" }],
    "phones":       ["+1-555..."],
    "emails":       ["hello@site.com"],
    "forms":        [{ "plugin": "elementor-pro", "fields": ["name","email","msg"] }],
    "trust_signals":["certified","money back"],
    "testimonials": { "present": true, "count": 4 },
    "faq_section":  { "present": true }
  },

  "elementor": {
    "is_elementor": true,
    "widget_counts": { "heading": 8, "button": 3, "image": 6, "form": 1 },
    "sections": [{
      "type": "section", "id": "abc",
      "widgets": [
        { "type": "heading", "level": "h1", "text": "..." },
        { "type": "button",  "text": "Get Quote", "link": "/contact" },
        { "type": "form",    "plugin": "elementor-pro", "fields": ["..."] }
      ]
    }]
  },

  "schema_blocks": [ { "@type": "Service", "...": "..." } ]
}
```

### Page classification

Each page is bucketed into one of:

| bucket | rule |
|---|---|
| `homepage` | site's static front page (`page_on_front`) |
| `article`  | `post_type = post` |
| `money`    | WooCommerce products, or slug/title contains commercial intent (`service`, `pricing`, `book`, `quote`, `contact`, `buy`, `apply`, `consultation`, `solution`, etc.) |
| `support`  | slug/title contains `about`, `faq`, `help`, `terms`, `privacy`, `careers`, etc. |
| `other`    | everything else |

### Hierarchy file (`hierarchy.json`)

```json
{
  "description": "Authority-flow grouping for AI structure analysis.",
  "counts": { "homepage": 1, "money_pages": 8, "support_pages": 5, "articles": 42, "other": 3 },
  "groups": {
    "homepage":      [ {"id":1,"url":"...","title":"...","post_type":"page","parent_id":0} ],
    "money_pages":   [ ... ],
    "support_pages": [ ... ],
    "articles":      [ ... ],
    "other":         [ ... ]
  }
}
```

### Internal-links file (`internal-links.json`)

```json
{
  "edges": [
    {
      "source": "https://site.com/about/",
      "target": "https://site.com/services/seo/",
      "anchor": "Learn more about SEO",
      "rel": [],
      "is_self": false,
      "source_post_type": "page",
      "source_classification": "support",
      "target_post_type": "page",
      "target_classification": "money",
      "target_id": 17
    }
  ],
  "anchor_text_frequency": [
    { "anchor": "learn more about seo", "count": 12 },
    { "anchor": "contact us",           "count":  9 }
  ]
}
```

## Installation

1. Download the plugin ZIP (`tse-site-exporter.zip`).
2. WP Admin → **Plugins → Add New → Upload Plugin** → upload the ZIP → **Install Now** → **Activate**.
3. Go to **Tools → TSE Site Exporter**.

Or manually: unzip into `/wp-content/plugins/` and activate.

## Usage

1. **Tools → TSE Site Exporter**.
2. Pick **Mode**:
   - **Quick** (default): caps at 500 posts. Safe for shared hosting.
   - **Full**: no cap.
3. Toggle options:
   - **Also fetch live URL** — re-fetches each rendered page via `wp_remote_get` so plugin-injected JSON-LD / dynamic HTML is captured. Slower.
   - **Check internal links for broken targets** — runs HEAD requests on each unique internal URL. Cached per export run. Slower.
   - **Include slice files** — also emit `seo-data.json`, `internal-links.json`, `external-links.json`, `cro-analysis.json`, `schema.json`, `elementor-structure.json`, `hierarchy.json`, `orphans.json`.
4. Click **Export Site Data** → ZIP downloads.

## Requirements

- WordPress 5.0+ / PHP 7.2+
- PHP `zip` and `dom` extensions
- Capability: `manage_options`

## Limitations

- **Single request**: very large sites (>5k posts) may exceed PHP `max_execution_time` / `memory_limit`. Use Full mode only after raising those, or run the Quick exports in batches. Background/Action-Scheduler mode is planned for V2.1.
- **Broken-link detection** is HEAD-based and depends on outbound HTTP being allowed; some servers reject HEAD (we fall back to a short GET).
- **Schema extraction** uses the **live rendered HTML** when the "Fetch live rendered URL" toggle is ON (now the default). This is required to see JSON-LD that SEO plugins (Rank Math / Yoast / Schema Pro / etc.) inject into `wp_head` rather than the post content. If you turn the toggle off, schema is only captured from JSON-LD inside `the_content` — usually almost empty.
- **Forms beyond known plugins** (CF7, WPForms, Gravity, Forminator, Ninja, Fluent, Elementor Pro Form, native `<form>`) are detected by presence only.
- **CRO heuristics** flag likely candidates (CTA phrases, trust keywords, testimonials). They are evidence-based hints, not assertions.
- **Elementor custom/unknown widgets** are kept under a generic `{ type: 'unknown', widget_type, text }` entry rather than skipped.
- Multisite: activate per site.

## Recommended Elementor parsing notes

The parser walks `_elementor_data` recursively and applies a per-widget mapper for: `heading`, `text-editor`, `theme-post-content`, `button`, `image`, `theme-post-featured-image`, `icon-box`, `image-box`, `icon-list`, `toggle`, `accordion`, `form`, `shortcode`, `video`, `testimonial`, `testimonial-carousel`. Unknown widget types still get their string settings harvested into a single `text` summary so AI ingestion still gets the words on the page.

## Versioning

- v2.1.2 — Content-structure quality round: Elementor Pro theme widgets (`theme-post-title` / `theme-page-title` / `theme-archive-title`) now emit as H1 with post-title fallback; addon heading widgets detected by `header_size + title` pattern; `tse_html_to_text` inserts word boundaries at block-level tags so `<h3>Fast</h3><p>Reliable</p>` no longer collapses into `FastReliable`; `text-editor`, `icon-box` descriptions and toggle/accordion answers now use the block-aware converter; `clean_text` joined with sentence punctuation; Elementor heading order is primary on Elementor pages (DOM as backfill); structural widgets (`breadcrumbs`, `nav-menu`, `spacer`, `divider`, `google_maps`, `social-icons`, `post-info`, `sidebar`) no longer pollute `clean_text`; `theme-site-logo` correctly typed as image.
- v2.1.1 — Extraction-quality fixes: live-HTML-first SEO, template expansion, heading dedup, plain_text fallback, tighter string filter.
- v2.1.0 — Structured-data audit: resilient JSON-LD extractor, classification, quality flags, site rollup.
- v2.0.0 — SEO/CRO/AI export, classification, hierarchy, anchor frequency, Elementor interpreter, slice files.
- v1.0.0 — Raw posts/pages/CPT/products export.
