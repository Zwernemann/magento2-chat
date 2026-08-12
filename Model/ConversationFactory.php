<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

use Magento\Framework\ObjectManagerInterface;

class ConversationFactory
{
    public function __construct(private readonly ObjectManagerInterface $objectManager) {}

    public function create(array $data = []): Conversation
    {
        return $this->objectManager->create(Conversation::class, $data);
    }
}
