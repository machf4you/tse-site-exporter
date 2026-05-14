<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Extracts CMS pages into a slim, structured dataset.
 *
 * Output schema (per page):
 *   id, identifier, title, url, meta_title, meta_description, meta_keywords,
 *   content_heading, is_active, page_layout, sort_order, created_at, updated_at
 */
class CmsPageExtractor
{
    /** @var PageRepositoryInterface */
    private $pageRepository;

    /** @var SearchCriteriaBuilder */
    private $searchCriteriaBuilder;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        PageRepositoryInterface $pageRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        StoreManagerInterface $storeManager
    ) {
        $this->pageRepository        = $pageRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->storeManager          = $storeManager;
    }

    public function extractAll(?int $storeId = null, ?string $storeCode = null): array
    {
        $criteria = $this->searchCriteriaBuilder->create();
        $result   = $this->pageRepository->getList($criteria);

        $base = '';
        try {
            $base = (string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_WEB);
        } catch (\Throwable $e) {}
        $base = rtrim($base, '/');

        $out = [];
        foreach ($result->getItems() as $p) {
            $identifier = (string) $p->getIdentifier();
            $row = [
                'id'               => (int) $p->getId(),
                'identifier'       => $identifier,
                'title'            => (string) $p->getTitle(),
                'url'              => $base ? $base . '/' . ltrim($identifier, '/') : '',
                'meta_title'       => (string) ($p->getMetaTitle() ?? ''),
                'meta_description' => (string) ($p->getMetaDescription() ?? ''),
                'meta_keywords'    => (string) ($p->getMetaKeywords() ?? ''),
                'content_heading'  => (string) ($p->getContentHeading() ?? ''),
                'is_active'        => (bool) $p->isActive(),
                'page_layout'      => (string) ($p->getPageLayout() ?? ''),
                'sort_order'       => (int) ($p->getSortOrder() ?? 0),
                'created_at'       => (string) ($p->getCreationTime() ?? ''),
                'updated_at'       => (string) ($p->getUpdateTime() ?? ''),
            ];
            if ($storeId   !== null) $row['store_id']   = $storeId;
            if ($storeCode !== null) $row['store_code'] = $storeCode;
            $out[] = $row;
        }
        return $out;
    }
}
