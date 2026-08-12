<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api\Data;

use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Single access point for conversation persistence.
 * Controllers and services should inject this instead of using Factory + ResourceModel directly.
 */
interface ConversationRepositoryInterface
{
    /**
     * @throws NoSuchEntityException
     */
    public function getById(int $id): ConversationInterface;

    /**
     * Find an existing conversation by its channel-scoped session ID.
     * Returns null when no conversation exists yet for this session.
     */
    public function getBySessionId(string $sessionId, int $storeId = 0): ?ConversationInterface;

    /**
     * Persist a new or updated conversation.
     */
    public function save(ConversationInterface $conversation): ConversationInterface;

    /**
     * Create a new, unsaved conversation instance.
     */
    public function create(): ConversationInterface;
}
