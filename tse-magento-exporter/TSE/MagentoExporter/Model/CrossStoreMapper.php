<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

/**
 * Cross-store reasoning: which SKUs are shared across storefronts, which
 * categories differ, and where product / category relationships span stores.
 *
 * Pure transformation logic — consumes per-store extractor output that the
 * Exporter has accumulated.
 */
class CrossStoreMapper
{
    /**
     * @param array $productsByStoreCode store_code => list of product records.
     * @return array {sku → [store_codes], duplicates, totals}
     */
    public function buildProductStoreMap(array $productsByStoreCode): array
    {
        $skuToStores = [];
        foreach ($productsByStoreCode as $storeCode => $products) {
            foreach ($products as $p) {
                $sku = (string) $p['sku'];
                if ('' === $sku) continue;
                $skuToStores[$sku][$storeCode] = [
                    'store_code'  => $storeCode,
                    'store_id'    => isset($p['store_id']) ? (int) $p['store_id'] : null,
                    'product_id'  => (int) $p['id'],
                    'url'         => isset($p['url']) ? (string) $p['url'] : '',
                    'status'      => isset($p['status']) ? (int) $p['status'] : 0,
                    'visibility'  => isset($p['visibility']) ? (int) $p['visibility'] : 0,
                    'website_ids' => isset($p['website_ids']) ? array_values((array) $p['website_ids']) : [],
                ];
            }
        }

        $entries = [];
        $shared  = [];
        foreach ($skuToStores as $sku => $stores) {
            $codes = array_keys($stores);
            $entry = [
                'sku'           => $sku,
                'store_count'   => count($codes),
                'store_codes'   => $codes,
                'is_shared'     => count($codes) > 1,
                'per_store'     => array_values($stores),
            ];
            $entries[] = $entry;
            if (count($codes) > 1) $shared[] = $entry;
        }

        return [
            'description' => 'Per-SKU store usage. is_shared=true means the SKU is visible in >1 store view.',
            'totals'      => [
                'unique_skus'    => count($entries),
                'shared_skus'    => count($shared),
                'store_views'    => count($productsByStoreCode),
            ],
            'products'    => $entries,
            'shared_only' => $shared,
        ];
    }

    /**
     * @param array $categoriesByStoreCode store_code => list of category records.
     * @return array description of category visibility differences across stores.
     */
    public function buildCategoryStoreDiff(array $categoriesByStoreCode): array
    {
        $idToStores = [];
        foreach ($categoriesByStoreCode as $storeCode => $cats) {
            foreach ($cats as $c) {
                $id = (int) $c['id'];
                $idToStores[$id][$storeCode] = [
                    'store_code'      => $storeCode,
                    'store_id'        => isset($c['store_id']) ? (int) $c['store_id'] : null,
                    'name'            => (string) $c['name'],
                    'is_active'       => (bool) $c['is_active'],
                    'include_in_menu' => (bool) $c['include_in_menu'],
                    'url'             => (string) ($c['url'] ?? ''),
                    'parent_id'       => (int) $c['parent_id'],
                ];
            }
        }

        $entries = [];
        $diverging = [];
        foreach ($idToStores as $id => $stores) {
            $codes = array_keys($stores);
            // Detect divergence: any difference in name / is_active / include_in_menu / parent.
            $names    = array_unique(array_map(fn($r) => $r['name'],            $stores));
            $actives  = array_unique(array_map(fn($r) => (int) $r['is_active'], $stores));
            $menus    = array_unique(array_map(fn($r) => (int) $r['include_in_menu'], $stores));
            $parents  = array_unique(array_map(fn($r) => (int) $r['parent_id'], $stores));
            $diverges = (count($names) > 1) || (count($actives) > 1) || (count($menus) > 1) || (count($parents) > 1);
            $entry = [
                'category_id' => $id,
                'store_count' => count($codes),
                'store_codes' => $codes,
                'diverges'    => $diverges,
                'per_store'   => array_values($stores),
            ];
            $entries[] = $entry;
            if ($diverges) $diverging[] = $entry;
        }

        return [
            'description' => 'Per-category cross-store presence + divergence detection (name/active/menu/parent).',
            'totals'      => [
                'unique_categories'  => count($entries),
                'diverging_categories' => count($diverging),
                'store_views'         => count($categoriesByStoreCode),
            ],
            'categories'        => $entries,
            'diverging_only'    => $diverging,
        ];
    }

    /**
     * Inspect product-relationship edges across stores to flag cross-store
     * references (when an upsell/related/crosssell target is unknown in the
     * current store — common in multi-website catalogs).
     */
    public function buildRelationshipCrossStoreFlags(array $relationshipsByStoreCode): array
    {
        $flags = [];
        $totals = ['stores' => 0, 'edges' => 0, 'target_unknown' => 0];
        foreach ($relationshipsByStoreCode as $storeCode => $rel) {
            $totals['stores']++;
            $edges = isset($rel['edges']) ? $rel['edges'] : [];
            foreach ($edges as $e) {
                $totals['edges']++;
                if (empty($e['target_known'])) {
                    $totals['target_unknown']++;
                    $flags[] = [
                        'store_code' => $storeCode,
                        'type'       => (string) ($e['type'] ?? ''),
                        'source_sku' => (string) ($e['source_sku'] ?? ''),
                        'target_sku' => (string) ($e['target_sku'] ?? ''),
                        'reason'     => 'Target SKU is not present in this store view — likely a different website or restricted by visibility.',
                    ];
                }
            }
        }
        return [
            'description' => 'Product relationships whose target SKU does not exist in the source store — flagged for cross-store inspection.',
            'totals'      => $totals,
            'flags'       => $flags,
        ];
    }
}
