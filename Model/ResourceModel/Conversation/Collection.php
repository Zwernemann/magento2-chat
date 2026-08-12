<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\ResourceModel\Conversation;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Zwernemann\Chat\Model\Conversation;
use Zwernemann\Chat\Model\ResourceModel\Conversation as ConversationResource;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(Conversation::class, ConversationResource::class);
    }
}
