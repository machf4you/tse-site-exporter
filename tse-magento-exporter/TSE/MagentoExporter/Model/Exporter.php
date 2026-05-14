<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

use Magento\Store\Model\App\Emulation;

/**
 * Multi-store orchestrator.
 *
 * Strategy:
 *   1. Build the store hierarchy (stores.json).
 *   2. For each store view, emulate the store context, then run all extractors.
 *      Store-specific URLs / metadata / status / visibility / category tree are
 *      preserved this way.
 *   3. Build cross-store maps (shared SKUs, diverging categories, cross-store
 *      relationship flags).
 *
 * Returns a flat map: { "filename inside zip" => array payload }.
 */
class Exporter
{
    /** @var StoreContextResolver */
    private $storeContext;

    /** @var Emulation */
    private $emulation;

    /** @var CategoryExtractor */
    private $categoryExtractor;

    /** @var ProductExtractor */
    private $productExtractor;

    /** @var CmsPageExtractor */
    private $cmsPageExtractor;

    /** @var RelationshipBuilder */
    private $relationshipBuilder;

    /** @var CrossStoreMapper */
    private $crossStoreMapper;

    public function __construct(
        StoreContextResolver $storeContext,
        Emulation $emulation,
        CategoryExtractor $categoryExtractor,
        ProductExtractor $productExtractor,
        CmsPageExtractor $cmsPageExtractor,
        RelationshipBuilder $relationshipBuilder,
        CrossStoreMapper $crossStoreMapper
    ) {
        $this->storeContext        = $storeContext;
        $this->emulation           = $emulation;
        $this->categoryExtractor   = $categoryExtractor;
        $this->productExtractor    = $productExtractor;
        $this->cmsPageExtractor    = $cmsPageExtractor;
        $this->relationshipBuilder = $relationshipBuilder;
        $this->crossStoreMapper    = $crossStoreMapper;
    }

    /**
     * @return array filename => payload
     */
    public function buildBundle(): array
    {
        $hierarchy = $this->storeContext->getHierarchy();
        $bundle    = [
            'stores.json' => $hierarchy,
        ];

        $productsByStoreCode      = [];
        $categoriesByStoreCode    = [];
        $relationshipsByStoreCode = [];

        foreach ($hierarchy['stores'] as $store) {
            $storeId   = (int) $store['id'];
            $storeCode = (string) $store['code'];

            $this->emulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);
            try {
                $categories = $this->categoryExtractor->extractAll($storeId, $storeCode);
                $products   = $this->productExtractor->extractAll($categories, $storeId, $storeCode);
                $cmsPages   = $this->cmsPageExtractor->extractAll($storeId, $storeCode);
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }

            $relationships  = $this->relationshipBuilder->buildProductRelationships($products);
            $categoryGraph  = $this->relationshipBuilder->buildCategoryGraph($categories);
            $productCatEdges= $this->relationshipBuilder->buildProductCategoryEdges($products);

            $prefix = 'stores/' . $storeCode . '/';
            $bundle[$prefix . 'products.json']               = [
                'description'  => sprintf('Products visible in store "%s" (id=%d).', $storeCode, $storeId),
                'store_id'     => $storeId,
                'store_code'   => $storeCode,
                'website_id'   => (int) $store['website_id'],
                'count'        => count($products),
                'products'     => $this->stripInternalFields($products),
            ];
            $bundle[$prefix . 'categories.json']             = [
                'description'  => sprintf('Categories visible in store "%s" (id=%d). Tree per store may differ.', $storeCode, $storeId),
                'store_id'     => $storeId,
                'store_code'   => $storeCode,
                'count'        => count($categories),
                'categories'   => $categories,
            ];
            $bundle[$prefix . 'cms-pages.json']              = [
                'description'  => sprintf('CMS pages assigned to store "%s" (id=%d).', $storeCode, $storeId),
                'store_id'     => $storeId,
                'store_code'   => $storeCode,
                'count'        => count($cmsPages),
                'pages'        => $cmsPages,
            ];
            $bundle[$prefix . 'product-relationships.json']  = array_merge($relationships, [
                'store_id'   => $storeId,
                'store_code' => $storeCode,
            ]);
            $bundle[$prefix . 'category-graph.json']         = array_merge($categoryGraph, [
                'store_id'   => $storeId,
                'store_code' => $storeCode,
            ]);
            $bundle[$prefix . 'product-category-edges.json'] = [
                'description' => 'product → category edges (graph-ready). Scoped to this store.',
                'store_id'    => $storeId,
                'store_code'  => $storeCode,
                'count'       => count($productCatEdges),
                'edges'       => $productCatEdges,
            ];

            $productsByStoreCode[$storeCode]      = $products;
            $categoriesByStoreCode[$storeCode]    = $categories;
            $relationshipsByStoreCode[$storeCode] = $relationships;
        }

        // Cross-store global maps.
        $bundle['product-store-map.json']           = $this->crossStoreMapper->buildProductStoreMap($productsByStoreCode);
        $bundle['category-store-diff.json']         = $this->crossStoreMapper->buildCategoryStoreDiff($categoriesByStoreCode);
        $bundle['relationship-cross-store-flags.json'] = $this->crossStoreMapper->buildRelationshipCrossStoreFlags($relationshipsByStoreCode);

        // Top-level manifest.
        $bundle['manifest.json'] = [
            'plugin'         => 'TSE Magento Exporter',
            'plugin_version' => '1.0.0',
            'generated_at'   => gmdate('c'),
            'multi_store'    => true,
            'totals'         => [
                'websites' => $hierarchy['totals']['websites'],
                'groups'   => $hierarchy['totals']['groups'],
                'stores'   => $hierarchy['totals']['stores'],
            ],
            'store_codes'    => array_map(fn($s) => $s['code'], $hierarchy['stores']),
        ];
        $bundle['manifest.json']['files'] = array_keys($bundle);

        return $bundle;
    }

    /**
     * Drop internal `product_links_raw` field from public product payload —
     * its content is already exposed in product-relationships.json.
     */
    private function stripInternalFields(array $products): array
    {
        foreach ($products as &$p) {
            unset($p['product_links_raw']);
        }
        unset($p);
        return $products;
    }
}
