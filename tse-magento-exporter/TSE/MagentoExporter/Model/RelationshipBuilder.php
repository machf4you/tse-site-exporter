<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

/**
 * Builds the product → product relationship dataset (related, upsell, crosssell)
 * and the category graph (nodes + parent → child edges).
 *
 * Pure transformation logic — no IO. Consumes the normalised product/category
 * arrays produced by the extractors.
 */
class RelationshipBuilder
{
    /**
     * Build relationship edges between products.
     *
     * @param array $products list of normalised product records.
     * @return array {description, totals, edges}
     */
    public function buildProductRelationships(array $products): array
    {
        // Index products by SKU for fast lookup of target IDs.
        $bySku = [];
        foreach ($products as $p) {
            $bySku[(string) $p['sku']] = $p;
        }

        $edges = [];
        $totals = ['related' => 0, 'upsell' => 0, 'crosssell' => 0];

        foreach ($products as $p) {
            $links = isset($p['product_links_raw']) && is_array($p['product_links_raw'])
                ? $p['product_links_raw']
                : [];
            foreach ($links as $l) {
                $type = strtolower((string) ($l['link_type'] ?? ''));
                if (! in_array($type, ['related', 'upsell', 'crosssell'], true)) continue;
                $targetSku = (string) ($l['linked_sku'] ?? '');
                if ('' === $targetSku) continue;
                $target = $bySku[$targetSku] ?? null;
                $edges[] = [
                    'type'        => $type,
                    'source_sku'  => (string) $p['sku'],
                    'source_id'   => (int) $p['id'],
                    'target_sku'  => $targetSku,
                    'target_id'   => $target ? (int) $target['id'] : null,
                    'position'    => (int) ($l['position'] ?? 0),
                    'target_known'=> $target !== null,
                ];
                $totals[$type]++;
            }
        }

        return [
            'description' => 'Directed product → product relationship edges (related, upsell, crosssell).',
            'totals'      => $totals,
            'edges'       => $edges,
        ];
    }

    /**
     * Build a directed category graph: parent_id → child_id edges.
     *
     * @param array $categories list of normalised category records.
     * @return array {description, totals, nodes, edges}
     */
    public function buildCategoryGraph(array $categories): array
    {
        $byId = [];
        foreach ($categories as $c) {
            $byId[(int) $c['id']] = $c;
        }

        $nodes = [];
        $edges = [];
        foreach ($byId as $id => $c) {
            $nodes[] = [
                'id'        => (int) $c['id'],
                'parent_id' => (int) $c['parent_id'],
                'name'      => (string) $c['name'],
                'level'     => (int) $c['level'],
                'is_root'   => ((int) $c['level']) === 1,
            ];
            $pid = (int) $c['parent_id'];
            if ($pid > 0 && isset($byId[$pid])) {
                $edges[] = ['source' => $pid, 'target' => (int) $c['id']];
            }
        }

        return [
            'description' => 'Directed category graph. Edges go parent_id (source) → child_id (target).',
            'totals'      => ['nodes' => count($nodes), 'edges' => count($edges)],
            'nodes'       => $nodes,
            'edges'       => $edges,
        ];
    }

    /**
     * Build product → category edge list (for graph consumers).
     */
    public function buildProductCategoryEdges(array $products): array
    {
        $edges = [];
        foreach ($products as $p) {
            $cats = (array) ($p['category_ids'] ?? []);
            foreach ($cats as $cid) {
                $edges[] = [
                    'product_id'   => (int) $p['id'],
                    'product_sku'  => (string) $p['sku'],
                    'category_id'  => (int) $cid,
                ];
            }
        }
        return $edges;
    }
}
