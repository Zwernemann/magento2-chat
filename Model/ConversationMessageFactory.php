<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

use Magento\Framework\ObjectManagerInterface;

class ConversationMessageFactory
{
    public function __construct(private readonly ObjectManagerInterface $objectManager) {}

    public function create(array $data = []): ConversationMessage
    {
        return $this->objectManager->create(ConversationMessage::class, $data);
    }
}
