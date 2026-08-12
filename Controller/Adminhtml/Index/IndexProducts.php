<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Zwernemann\Chat\Model\Rag\ProductIndexer;

class IndexProducts extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_Chat::conversations';

    public function __construct(
        Context $context,
        private readonly ProductIndexer $productIndexer
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\Controller\Result\Redirect
    {
        try {
            $this->productIndexer->indexAll(force: true);
            $this->messageManager->addSuccessMessage(__('Product index updated successfully.'));
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage(__('Indexing failed: %1', $e->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('zwernemann_chat/index/index');
    }
}
