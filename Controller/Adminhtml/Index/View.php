<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Controller\Adminhtml\Index;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Zwernemann\Chat\Api\Data\ConversationRepositoryInterface;

class View extends Action
{
    public const ADMIN_RESOURCE = 'Zwernemann_Chat::conversations';

    public function __construct(
        Context $context,
        private readonly PageFactory                     $pageFactory,
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly Registry                        $registry
    ) {
        parent::__construct($context);
    }

    public function execute(): \Magento\Framework\View\Result\Page|\Magento\Framework\Controller\Result\Redirect
    {
        $id = (int)$this->getRequest()->getParam('id');
        try {
            $conversation = $this->conversationRepository->getById($id);
        } catch (NoSuchEntityException) {
            $this->messageManager->addErrorMessage(__('Conversation not found.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/');
        }

        $this->registry->register('current_conversation', $conversation);

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Conversation #%1', $id));
        return $page;
    }
}
