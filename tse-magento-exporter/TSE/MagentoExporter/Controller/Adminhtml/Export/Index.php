<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Controller\Adminhtml\Export;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    public const ADMIN_RESOURCE = 'TSE_MagentoExporter::tse_export';

    /** @var PageFactory */
    private $resultPageFactory;

    public function __construct(Context $context, PageFactory $resultPageFactory)
    {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('TSE_MagentoExporter::tse_export');
        $page->getConfig()->getTitle()->prepend(__('TSE Magento Exporter'));
        return $page;
    }
}
