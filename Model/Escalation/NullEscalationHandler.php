<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Escalation;

use Zwernemann\Chat\Api\EscalationHandlerInterface;
use Zwernemann\Chat\Api\Data\ConversationInterface;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;

/**
 * Default escalation handler for the free Chat module: never escalates and
 * never holds a conversation, so every message is answered automatically.
 *
 * The premium module overrides the EscalationHandlerInterface preference with
 * a real implementation.
 */
class NullEscalationHandler implements EscalationHandlerInterface
{
    public function isOnHold(ConversationInterface $conversation): bool
    {
        return false;
    }

    public function detect(
        UnifiedMessageInterface $message,
        array $llmResult,
        array $ragResults,
        int $consecutiveClarifications,
        int $storeId
    ): ?string {
        return null;
    }

    public function escalate(ConversationInterface $conversation, string $reason, int $storeId = 0): void
    {
        // no-op
    }
}
