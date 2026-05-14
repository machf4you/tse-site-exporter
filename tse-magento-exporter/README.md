# TSE Magento Exporter — V1.0.0

A Magento 2 module that exports **multi-store-aware structured ecommerce intelligence** as a downloadable ZIP of JSON files.

## What it produces

Multi-store install with store views `hf` / `cbs` / `mt` produces:

```
manifest.json                         # totals, store codes, file index
stores.json                           # websites → groups → store views
product-store-map.json                # SKU → list of store views (shared vs single-store)
category-store-diff.json              # categories that diverge across stores (name/active/menu/parent)
relationship-cross-store-flags.json   # product links pointing to targets unknown in source store

stores/hf/products.json
stores/hf/categories.json
stores/hf/cms-pages.json
stores/hf/product-relationships.json
stores/hf/category-graph.json
stores/hf/product-category-edges.json

stores/cbs/...
stores/mt/...
```

## Architecture

| Component                | Responsibility                                                            |
| ------------------------ | ------------------------------------------------------------------------- |
| `StoreContextResolver`   | Reads websites / store groups / store views via Magento repositories.     |
| `CategoryExtractor`      | Normalises categories with parent / path / children / per-store metadata. |
| `ProductExtractor`       | Normalises products with stock, attributes, images, website_ids, store_ids, raw product links. Paginated (200/page) for memory safety. |
| `CmsPageExtractor`       | Normalises CMS pages with URLs scoped to the current store.               |
| `RelationshipBuilder`    | Builds product → product edges (related/upsell/crosssell) and category parent → child graph. |
| `CrossStoreMapper`       | Cross-store reasoning: shared SKUs, diverging categories, dangling links. |
| `Exporter`               | Orchestrator. Uses `Magento\Store\Model\App\Emulation` to switch context per store view so URLs / metadata / status / visibility / category tree all reflect that store. |
| `ZipBuilder`             | Streams the bundle to a single ZIP.                                       |

## Multi-store correctness

* Each per-store record carries `store_id` + `store_code`.
* Products additionally carry `website_ids` and `store_ids` (assigned scopes).
* URLs, meta titles, statuses, and visibility flags are extracted **inside store emulation**, so they reflect that store view's overrides.
* `product-store-map.json` surfaces which SKUs are shared across storefronts.
* `category-store-diff.json` highlights categories whose name / `is_active` / `include_in_menu` / `parent_id` differs between store views.
* Relationship edges that resolve to a target SKU not visible in the source store are surfaced in `relationship-cross-store-flags.json` for cross-website inspection.

## Install

1. Unzip into `app/code/TSE/MagentoExporter/`.
2. From your Magento root:
   ```
   bin/magento module:enable TSE_MagentoExporter
   bin/magento setup:upgrade
   bin/magento setup:di:compile      # production mode only
   bin/magento cache:clean
   ```
3. Visit **System → Tools → TSE Magento Exporter** and click **Download multi-store export ZIP**.

## Permissions

Wired through ACL — the role permission lives at **System → Tools → TSE Magento Exporter**.

## Roadmap

* V1: structured extraction + multi-store mapping (this release).
* V2: AI-ready summary slices (per-store + cross-store roll-ups), modelled after the WordPress TSE exporter.
* V3: AI analysis layer (recommendations, internal linking, cannibalisation detection).
