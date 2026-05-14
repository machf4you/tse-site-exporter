<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Extracts categories into a normalised, graph-ready dataset.
 *
 * Output schema (per category):
 *   id, parent_id, name, level, path, path_ids, path_names,
 *   url, url_key, meta_title, meta_description, meta_keywords,
 *   is_active, include_in_menu, position, children_ids, product_count
 */
class CategoryExtractor
{
    /** @var CategoryListInterface */
    private $categoryList;

    /** @var CategoryRepositoryInterface */
    private $categoryRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        CategoryListInterface $categoryList,
        CategoryRepositoryInterface $categoryRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        StoreManagerInterface $storeManager
    ) {
        $this->categoryList          = $categoryList;
        $this->categoryRepository    = $categoryRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->storeManager          = $storeManager;
    }

    /**
     * @param int|null $storeId Stamp records with this store context (does NOT
     *                          switch emulation — that's the Exporter's job).
     * @param string|null $storeCode
     * @return array list of category records.
     */
    public function extractAll(?int $storeId = null, ?string $storeCode = null): array
    {
        $criteria = $this->searchCriteriaBuilder->create();
        $result   = $this->categoryList->getList($criteria);

        $byId = [];
        foreach ($result->getItems() as $cat) {
            $row = $this->normalise($cat);
            if ($storeId   !== null) $row['store_id']   = $storeId;
            if ($storeCode !== null) $row['store_code'] = $storeCode;
            $byId[(int) $cat->getId()] = $row;
        }

        // Resolve path_names and children_ids using the full id index.
        foreach ($byId as $id => &$row) {
            $row['path_names'] = $this->resolvePathNames($row['path_ids'], $byId);
        }
        unset($row);

        foreach ($byId as $id => $row) {
            $pid = $row['parent_id'];
            if ($pid && isset($byId[$pid])) {
                $byId[$pid]['children_ids'][] = (int) $id;
            }
        }

        return array_values($byId);
    }

    /**
     * @return array {id, parent_id, name, level, path, path_ids, url, ...}
     */
    public function normalise($category): array
    {
        $path = (string) $category->getPath();
        $pathIds = array_values(array_filter(array_map('intval', explode('/', $path))));

        return [
            'id'               => (int) $category->getId(),
            'parent_id'        => (int) $category->getParentId(),
            'name'             => (string) $category->getName(),
            'level'            => (int) $category->getLevel(),
            'path'             => $path,
            'path_ids'         => $pathIds,
            'path_names'       => [],
            'url'              => method_exists($category, 'getUrl') ? (string) $category->getUrl() : '',
            'url_key'          => (string) ($category->getUrlKey() ?? ''),
            'meta_title'       => (string) ($category->getMetaTitle() ?? ''),
            'meta_description' => (string) ($category->getMetaDescription() ?? ''),
            'meta_keywords'    => (string) ($category->getMetaKeywords() ?? ''),
            'is_active'        => (bool) $category->getIsActive(),
            'include_in_menu'  => (bool) $category->getIncludeInMenu(),
            'position'         => (int) ($category->getPosition() ?? 0),
            'children_ids'     => [],
            'product_count'    => (int) ($category->getProductCount() ?? 0),
            'is_root'          => ((int) $category->getLevel()) === 1,
        ];
    }

    /**
     * Resolve a category's ancestor names (excluding root) from id path.
     */
    private function resolvePathNames(array $pathIds, array $byId): array
    {
        $out = [];
        foreach ($pathIds as $id) {
            if (isset($byId[$id]) && $byId[$id]['level'] >= 1) {
                $out[] = $byId[$id]['name'];
            }
        }
        return $out;
    }
}
