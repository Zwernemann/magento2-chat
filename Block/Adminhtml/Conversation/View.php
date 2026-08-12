<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Block\Adminhtml\Conversation;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Zwernemann\Chat\Model\Conversation;
use Zwernemann\Chat\Model\ResourceModel\ConversationMessage as MessageResource;

class View extends Template
{
    protected $_template = 'Zwernemann_Chat::conversation/view.phtml';

    public function __construct(
        Context $context,
        private readonly Registry       $registry,
        private readonly MessageResource $messageResource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getConversation(): ?Conversation
    {
        return $this->registry->registry('current_conversation');
    }

    /** @return array<int, array<string, mixed>> */
    public function getMessages(): array
    {
        $conversation = $this->getConversation();
        if (!$conversation) return [];
        return $this->messageResource->getMessagesByConversationId((int)$conversation->getId(), 100);
    }

    public function getBackUrl(): string
    {
        return $this->getUrl('zwernemann_chat/index/index');
    }
}
