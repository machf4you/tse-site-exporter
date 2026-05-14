<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use TSE\MagentoExporter\Model\StoreContextResolver;

class Form extends Template
{
    /** @var StoreContextResolver */
    private $storeContext;

    public function __construct(
        Context $context,
        StoreContextResolver $storeContext,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->storeContext = $storeContext;
    }

    public function getDownloadUrl(): string
    {
        return $this->getUrl('tsemagento/export/download');
    }

    public function getStoreHierarchy(): array
    {
        try {
            return $this->storeContext->getHierarchy();
        } catch (\Throwable $e) {
            return ['websites' => [], 'groups' => [], 'stores' => [], 'totals' => ['websites' => 0, 'groups' => 0, 'stores' => 0]];
        }
    }
}
