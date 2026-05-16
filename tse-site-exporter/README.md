# TSE Site Exporter — V2.9

A WordPress plugin that exports **AI-ready structured website intelligence** as a single downloadable ZIP of JSON files. Not a raw WordPress dump — every page is reduced to a canonical record covering SEO, content hierarchy, FAQs, links (with cross-references), media, CRO signals, schema, and interpreted Elementor structure. Includes a site-wide hierarchy, anchor-text frequency, orphan detection, an internal-link relationship graph with per-page metrics, a Weighted Internal Linking Engine, compact AI-analysis-ready summary files, **(V2.5) an AI Analysis Execution Layer that calls OpenAI / Anthropic / Gemini directly from PHP using user-supplied keys**, **(V2.8) a lightweight operational dashboard with run history, organised report panels and an in-admin iframe viewer**, and **(V2.9) a Strategic SEO Configuration layer plus implementation-style recommendation wording**.

## Strategic SEO Configuration (V2.9)

Optional admin section under **Tools → TSE Site Exporter → Strategic SEO Configuration**. Declare your business intent across six buckets — one URL or path per line:

- Money Pages
- Support Pages
- Location Pages
- Priority URLs
- Primary Conversion Pages
- Protected URLs *(never recommend changing / merging / noindexing)*

The exporter writes two new bundle files:

- `strategy-config.json` — your declared buckets (traceability).
- `strategy-mismatch.json` — deterministic declared-vs-actual findings such as *"declared money page receives only 2 internal links"* or *"primary conversion page has no inbound link from any money page"*.

Both files feed the AI runner as additional context, and the main HTML report renders a dedicated **Strategy vs reality** section.

## Implementation-style recommendations (V2.9)

System prompts and the internal-link report were rewritten to emit Jira-ticket-style instructions, not SEO essays. Every link suggestion is now rendered as a card:

```
Add internal link
FROM:  /blog/bathroom-tips/
TO:    /bathroom-renovations/
Suggested anchor: "bathroom renovation services"
Reason: Readers of the tips article are the exact audience
        considering booking a renovation.
```

Banned jargon (`PageRank`, `link equity`, `passes strong authority`, `topical authority signals`) is forbidden at prompt level; the renderer fall back to the structured `source_url` / `target_url` / `suggested_anchor` / `reason` fields when the LLM is concise.

## Operational Dashboard (V2.8)

Under **Tools → TSE Site Exporter** a dashboard block is added at the bottom of the page:

- **Export / Analysis history** — every export and AI run is logged with date/time, provider, model, mode, status (success / failure). Capped at 50 entries; the underlying ZIPs persist on disk so reports remain reopenable after refresh.
- **Recent Reports** — grouped panels for the latest export and latest AI run:
  - *Exports* → Raw JSON
  - *AI Analysis* → AI Reports, Internal Link Reports, Cluster Reports, Raw JSON, plus a one-click ZIP download
- **In-admin viewer** — HTML reports open inside wp-admin in a clean iframe panel (no manual ZIP browsing). JSON files open inline in a new tab.
- **Delete** — one-click per-run removal (entry + its ZIP).

No React, no charts, no SaaS chrome — just pure WP admin markup backed by a single `wp_options` row (`tse_site_exporter_runs`) and the existing `wp-content/uploads/tse-site-exporter/` directory.

## AI Analysis (V2.5)

A new section in the admin page lets you configure provider keys/models and click **Run AI Analysis** to download a ZIP of structured LLM findings:

```
ai-recommendations.json           # prioritised action plan
ai-internal-link-opportunities.json   # refined link suggestions with anchors
ai-cluster-analysis.json          # per-cluster findings + bridge suggestions
ai-content-gap-signals.json       # missing support / cannibalisation / metadata
```

Every output uses the same schema:
```
{ "items": [
    { "priority": "high|medium|low",
      "issue": "<short>",
      "affected_pages": ["url"],
      "recommendation": "<short specific action>",
      "confidence_score": 0.0..1.0,
      ... optional extras per file ... }
] }
```

**Keys** are resolved in this order (constants first, UI second):
- `TSE_OPENAI_KEY` / `TSE_ANTHROPIC_KEY` / `TSE_GEMINI_KEY` defined in `wp-config.php`, **or**
- masked UI fields on **Tools → TSE Site Exporter** (stored in `wp_options.tse_ai_settings`).

**Models** (override via `TSE_OPENAI_MODEL` / `TSE_ANTHROPIC_MODEL` / `TSE_GEMINI_MODEL` or UI):
- OpenAI: `gpt-5.2`
- Anthropic: `claude-sonnet-4-5`
- Gemini: `gemini-3-pro`

Only the compact `ai-*.json` summary slices are sent to the LLM — no Elementor JSON, no raw HTML, no `plain_text`.

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

# V2 relationship engine
internal-link-graph.json     # full directed graph: nodes + edges + per-page metrics
orphan-pages.json            # pages with zero incoming internal links
weak-pages.json              # pages with weak incoming support
relationship-summary.json    # totals, top hubs / authorities, classification flow

# V2.3 weighted internal linking engine
authority-map.json           # per-page authority + composite scores (0..100), ranked
weighted-link-graph.json     # edges with computed authority-propagation weights
strategic-pages.json         # page → {money, support, article, service, location, product, category, homepage, other}
cluster-signals.json         # weakly-connected components, main vs isolated clusters
intelligence-flags.json      # overlinked, under-supported important, high-out/weak-in

# V2.4 AI analysis layer (compact, LLM-friendly)
ai-site-summary.json         # totals, distributions, top authorities/hubs, issue counts
ai-page-summaries.json       # slim per-page records (no Elementor, no raw text) + issue flags
ai-linking-summary.json      # weak money pages, orphans/near-orphans, dup metadata, linking opportunities
ai-cluster-summary.json      # main vs isolated clusters with recommended bridge sources
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

- v2.2.0 — **Internal Link Relationship Engine**: new `includes/relationships.php` builds a full internal-link graph in one pass — per-page metrics (incoming/outgoing counts, unique linking pages, unique target pages, incoming/outgoing anchor breakdown, inbound/outbound classification breakdown), threshold-based orphan/weak/excessive-outbound detection (homepage excluded from orphan/weak), classification flow matrix (source classification → target classification edge counts), top 10 hubs (most outgoing) and top 10 authorities (most incoming), global anchor-text frequency. Per-page `relationships` object (slim counts) injected into every PageRecord. Four new slice files: `internal-link-graph.json`, `orphan-pages.json`, `weak-pages.json`, `relationship-summary.json`.
- v2.1.3 — Stabilisation: normalised heading dedup, decoded entities, sliding-window clean_text dedup, H1 post-title safety net, vestigial field cleanup.
- v2.1.2 — Content-structure quality: theme-builder widgets, addon-heading pattern, `tse_html_to_text` word boundaries, sentence-punctuation join, structural-widget exclusion.
- v2.1.1 — Extraction-quality fixes: live-HTML-first SEO, template expansion.
- v2.1.0 — Structured-data audit: resilient JSON-LD extractor, classification, quality flags, site rollup.
- v2.0.0 — SEO/CRO/AI export, classification, hierarchy, anchor frequency, Elementor interpreter, slice files.
- v1.0.0 — Raw posts/pages/CPT/products export.
