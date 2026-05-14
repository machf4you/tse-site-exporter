<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Controller\Adminhtml\Export;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\Filesystem\DirectoryList;
use TSE\MagentoExporter\Model\Exporter;
use TSE\MagentoExporter\Model\ZipBuilder;

class Download extends Action
{
    public const ADMIN_RESOURCE = 'TSE_MagentoExporter::tse_export';

    /** @var Exporter */
    private $exporter;

    /** @var ZipBuilder */
    private $zipBuilder;

    /** @var FileFactory */
    private $fileFactory;

    public function __construct(
        Context $context,
        Exporter $exporter,
        ZipBuilder $zipBuilder,
        FileFactory $fileFactory
    ) {
        parent::__construct($context);
        $this->exporter    = $exporter;
        $this->zipBuilder  = $zipBuilder;
        $this->fileFactory = $fileFactory;
    }

    public function execute()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        try {
            $bundle  = $this->exporter->buildBundle();
            $zipPath = $this->zipBuilder->build($bundle);

            $filename = 'tse-magento-export-' . gmdate('Ymd-His') . '.zip';
            return $this->fileFactory->create(
                $filename,
                [
                    'type'  => 'filename',
                    'value' => $zipPath,
                    'rm'    => true,
                ],
                DirectoryList::TMP,
                'application/zip'
            );
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('TSE export failed: %1', $e->getMessage()));
            return $this->resultRedirectFactory->create()->setPath('tsemagento/export/index');
        }
    }
}
