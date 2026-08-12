<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

use Magento\Framework\Exception\NoSuchEntityException;
use Zwernemann\Chat\Api\Data\ConversationInterface;
use Zwernemann\Chat\Api\Data\ConversationRepositoryInterface;

/**
 * Centralises all conversation persistence.
 * Wraps ConversationFactory + ResourceModel\Conversation so callers
 * do not need to know about the underlying storage mechanism.
 */
class ConversationRepository implements ConversationRepositoryInterface
{
    public function __construct(
        private readonly ConversationFactory        $factory,
        private readonly ResourceModel\Conversation $resource
    ) {}

    public function getById(int $id): ConversationInterface
    {
        $conversation = $this->factory->create();
        $this->resource->load($conversation, $id);
        if (!$conversation->getId()) {
            throw new NoSuchEntityException(__('Conversation with ID %1 does not exist.', $id));
        }
        return $conversation;
    }

    public function getBySessionId(string $sessionId, int $storeId = 0): ?ConversationInterface
    {
        $conversation = $this->factory->create();
        $this->resource->loadBySessionId($conversation, $sessionId, $storeId);
        return $conversation->getId() ? $conversation : null;
    }

    public function save(ConversationInterface $conversation): ConversationInterface
    {
        $this->resource->save($conversation);
        return $conversation;
    }

    public function create(): ConversationInterface
    {
        return $this->factory->create();
    }
}
