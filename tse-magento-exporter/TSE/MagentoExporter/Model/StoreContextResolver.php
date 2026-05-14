<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

use Magento\Store\Api\GroupRepositoryInterface;
use Magento\Store\Api\StoreRepositoryInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Reads the full multi-store hierarchy: websites → store groups → store views.
 *
 * Output:
 *   {
 *     "websites":     [{id, code, name, default_group_id, is_default}],
 *     "groups":       [{id, code, name, website_id, root_category_id, default_store_id}],
 *     "stores":       [{id, code, name, website_id, group_id, base_url, secure_base_url, is_active}]
 *   }
 *
 * Also offers iteration over store views for the orchestrator.
 */
class StoreContextResolver
{
    /** @var WebsiteRepositoryInterface */
    private $websiteRepository;

    /** @var GroupRepositoryInterface */
    private $groupRepository;

    /** @var StoreRepositoryInterface */
    private $storeRepository;

    /** @var StoreManagerInterface */
    private $storeManager;

    public function __construct(
        WebsiteRepositoryInterface $websiteRepository,
        GroupRepositoryInterface $groupRepository,
        StoreRepositoryInterface $storeRepository,
        StoreManagerInterface $storeManager
    ) {
        $this->websiteRepository = $websiteRepository;
        $this->groupRepository   = $groupRepository;
        $this->storeRepository   = $storeRepository;
        $this->storeManager      = $storeManager;
    }

    /**
     * @return array hierarchy payload (for stores.json).
     */
    public function getHierarchy(): array
    {
        $websites = [];
        $defaultWebsiteId = (int) $this->storeManager->getDefaultStoreView()->getWebsiteId();
        foreach ($this->websiteRepository->getList() as $w) {
            if ((int) $w->getId() === 0) continue; // Admin website
            $websites[] = [
                'id'               => (int) $w->getId(),
                'code'             => (string) $w->getCode(),
                'name'             => (string) $w->getName(),
                'default_group_id' => method_exists($w, 'getDefaultGroupId') ? (int) $w->getDefaultGroupId() : 0,
                'is_default'       => (int) $w->getId() === $defaultWebsiteId,
            ];
        }

        $groups = [];
        foreach ($this->groupRepository->getList() as $g) {
            if ((int) $g->getId() === 0) continue;
            $groups[] = [
                'id'               => (int) $g->getId(),
                'code'             => method_exists($g, 'getCode') ? (string) $g->getCode() : '',
                'name'             => (string) $g->getName(),
                'website_id'       => (int) $g->getWebsiteId(),
                'root_category_id' => (int) $g->getRootCategoryId(),
                'default_store_id' => (int) $g->getDefaultStoreId(),
            ];
        }

        $stores = [];
        foreach ($this->storeRepository->getList() as $s) {
            if ((int) $s->getId() === 0) continue; // Admin store
            $base = ''; $secure = '';
            try { $base   = (string) $s->getBaseUrl(UrlInterface::URL_TYPE_WEB,  false); } catch (\Throwable $e) {}
            try { $secure = (string) $s->getBaseUrl(UrlInterface::URL_TYPE_WEB,  true);  } catch (\Throwable $e) {}
            $stores[] = [
                'id'              => (int) $s->getId(),
                'code'            => (string) $s->getCode(),
                'name'            => (string) $s->getName(),
                'website_id'      => (int) $s->getWebsiteId(),
                'group_id'        => method_exists($s, 'getStoreGroupId') ? (int) $s->getStoreGroupId() : 0,
                'base_url'        => $base,
                'secure_base_url' => $secure,
                'is_active'       => method_exists($s, 'isActive') ? (bool) $s->isActive() : true,
            ];
        }

        return [
            'description' => 'Magento multi-store hierarchy: websites → store groups → store views. Use store.id when scoping exports.',
            'totals'      => [
                'websites' => count($websites),
                'groups'   => count($groups),
                'stores'   => count($stores),
            ],
            'websites' => $websites,
            'groups'   => $groups,
            'stores'   => $stores,
        ];
    }

    /**
     * @return array list of store-view rows from getHierarchy()['stores'].
     */
    public function getStoreViews(): array
    {
        return $this->getHierarchy()['stores'];
    }
}
