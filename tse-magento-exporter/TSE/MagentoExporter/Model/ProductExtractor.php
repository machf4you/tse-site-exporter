<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Extracts products into a normalised, AI-ready dataset.
 *
 * Pagination is used (page size = 200 by default) so large catalogs don't
 * blow up memory.
 *
 * Output schema (per product):
 *   id, sku, name, type, status, visibility,
 *   url, url_key, meta_title, meta_description, meta_keyword,
 *   price, special_price, currency,
 *   stock_status, qty, manage_stock,
 *   category_ids, categories, attributes, images,
 *   product_links_raw (used by RelationshipBuilder),
 *   created_at, updated_at
 */
class ProductExtractor
{
    public const DEFAULT_PAGE_SIZE = 200;

    /** @var ProductRepositoryInterface */
    private $productRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var StockRegistryInterface */
    private $stockRegistry;

    /** @var StoreManagerInterface */
    private $storeManager;

    /** @var int */
    private $pageSize;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        StockRegistryInterface $stockRegistry,
        StoreManagerInterface $storeManager,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ) {
        $this->productRepository     = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockRegistry         = $stockRegistry;
        $this->storeManager          = $storeManager;
        $this->pageSize              = $pageSize;
    }

    /**
     * @param array $categoriesById Output of CategoryExtractor::extractAll() (already store-scoped).
     * @param int|null    $storeId  Stamp records with this store context.
     * @param string|null $storeCode
     * @return array list of product records.
     */
    public function extractAll(array $categoriesById = [], ?int $storeId = null, ?string $storeCode = null): array
    {
        $catIndex = [];
        foreach ($categoriesById as $c) {
            $catIndex[(int) $c['id']] = $c;
        }

        $out = [];
        $page = 1;
        do {
            $criteria = $this->searchCriteriaBuilder
                ->setPageSize($this->pageSize)
                ->setCurrentPage($page)
                ->create();
            $result = $this->productRepository->getList($criteria);
            foreach ($result->getItems() as $p) {
                $row = $this->normalise($p, $catIndex);
                if ($storeId   !== null) $row['store_id']   = $storeId;
                if ($storeCode !== null) $row['store_code'] = $storeCode;
                $out[] = $row;
            }
            $totalPages = (int) ceil($result->getTotalCount() / max(1, $this->pageSize));
            $page++;
        } while ($page <= $totalPages);

        return $out;
    }

    /**
     * @return array
     */
    public function normalise($product, array $catIndex = []): array
    {
        $sku = (string) $product->getSku();
        $id  = (int) $product->getId();

        $stockStatus = 'unknown';
        $qty = 0;
        $manageStock = false;
        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
            $stockStatus = $stockItem->getIsInStock() ? 'in_stock' : 'out_of_stock';
            $qty = (float) $stockItem->getQty();
            $manageStock = (bool) $stockItem->getManageStock();
        } catch (\Throwable $e) {
            // Stock data optional — keep defaults.
        }

        $url = '';
        if (method_exists($product, 'getProductUrl')) {
            try { $url = (string) $product->getProductUrl(); } catch (\Throwable $e) {}
        }

        $currency = '';
        try {
            $currency = (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Throwable $e) {}

        $categoryIds = array_values(array_map('intval', (array) $product->getCategoryIds()));
        $categories  = [];
        foreach ($categoryIds as $cid) {
            if (isset($catIndex[$cid])) {
                $cat = $catIndex[$cid];
                $categories[] = [
                    'id'         => $cat['id'],
                    'name'       => $cat['name'],
                    'url_key'    => $cat['url_key'],
                    'path_names' => $cat['path_names'],
                ];
            } else {
                $categories[] = ['id' => $cid, 'name' => null, 'url_key' => null, 'path_names' => []];
            }
        }

        $attrs = [];
        if (method_exists($product, 'getCustomAttributes')) {
            foreach ((array) $product->getCustomAttributes() as $attr) {
                if (! is_object($attr)) continue;
                $code  = (string) $attr->getAttributeCode();
                $value = $attr->getValue();
                $excluded = ['description', 'short_description', 'meta_title', 'meta_description',
                             'meta_keyword', 'image', 'small_image', 'thumbnail', 'url_key', 'options_container',
                             'gift_message_available', 'special_price', 'special_from_date', 'special_to_date'];
                if (in_array($code, $excluded, true)) continue;
                if (is_array($value)) $value = array_map('strval', $value);
                else                  $value = is_scalar($value) ? (string) $value : null;
                $attrs[$code] = $value;
            }
        }

        $images = [];
        if (method_exists($product, 'getMediaGalleryEntries')) {
            $entries = (array) $product->getMediaGalleryEntries();
            foreach ($entries as $e) {
                if (! is_object($e)) continue;
                $images[] = [
                    'file'         => method_exists($e, 'getFile') ? (string) $e->getFile() : '',
                    'label'        => method_exists($e, 'getLabel') ? (string) ($e->getLabel() ?? '') : '',
                    'position'     => method_exists($e, 'getPosition') ? (int) ($e->getPosition() ?? 0) : 0,
                    'disabled'     => method_exists($e, 'isDisabled') ? (bool) $e->isDisabled() : false,
                    'types'        => method_exists($e, 'getTypes') ? array_values((array) $e->getTypes()) : [],
                ];
            }
        }

        return [
            'id'               => $id,
            'sku'              => $sku,
            'name'             => (string) $product->getName(),
            'type'             => (string) $product->getTypeId(),
            'status'           => (int) $product->getStatus(),
            'visibility'       => (int) $product->getVisibility(),
            'url'              => $url,
            'url_key'          => (string) ($product->getUrlKey() ?? ''),
            'meta_title'       => (string) ($product->getMetaTitle() ?? ''),
            'meta_description' => (string) ($product->getMetaDescription() ?? ''),
            'meta_keyword'     => (string) ($product->getMetaKeyword() ?? ''),
            'price'            => (float) ($product->getPrice() ?? 0),
            'special_price'    => $product->getSpecialPrice() !== null ? (float) $product->getSpecialPrice() : null,
            'currency'         => $currency,
            'stock_status'     => $stockStatus,
            'qty'              => $qty,
            'manage_stock'     => $manageStock,
            'website_ids'      => method_exists($product, 'getWebsiteIds') ? array_values(array_map('intval', (array) $product->getWebsiteIds())) : [],
            'store_ids'        => method_exists($product, 'getStoreIds')   ? array_values(array_map('intval', (array) $product->getStoreIds()))   : [],
            'category_ids'     => $categoryIds,
            'categories'       => $categories,
            'attributes'       => $attrs,
            'images'           => $images,
            'created_at'       => (string) ($product->getCreatedAt() ?? ''),
            'updated_at'       => (string) ($product->getUpdatedAt() ?? ''),
            'product_links_raw'=> $this->collectProductLinks($product),
        ];
    }

    /**
     * @return array list of {link_type, linked_sku, position}
     */
    private function collectProductLinks($product): array
    {
        $out = [];
        if (! method_exists($product, 'getProductLinks')) return $out;
        $links = (array) $product->getProductLinks();
        foreach ($links as $link) {
            if (! is_object($link)) continue;
            $out[] = [
                'link_type'   => (string) $link->getLinkType(),
                'linked_sku'  => (string) $link->getLinkedProductSku(),
                'position'    => method_exists($link, 'getPosition') ? (int) ($link->getPosition() ?? 0) : 0,
            ];
        }
        return $out;
    }
}
