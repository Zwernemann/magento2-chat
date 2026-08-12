<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api;

use Zwernemann\Chat\Api\Data\ConversationInterface;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;

/**
 * Escalation seam: hands a conversation off to a human team.
 *
 * The free Chat module ships a null implementation (never escalates, never
 * holds), so the pipeline always answers automatically. The premium module
 * overrides the preference to add confidence/keyword/order-value detection,
 * a hold state, and admin notification.
 *
 * The MessageProcessor keeps ownership of persistence and channel delivery;
 * this handler only makes the decision (isOnHold/detect) and performs the
 * conversation state transition (escalate).
 */
interface EscalationHandlerInterface
{
    /**
     * Whether the conversation is currently held for manual review, so the
     * inbound message must not be answered automatically (Step 2b).
     */
    public function isOnHold(ConversationInterface $conversation): bool;

    /**
     * Decide whether this turn should be escalated. Returns a human-readable
     * reason string when escalation is warranted, or null to answer normally.
     *
     * @param array<string, mixed>            $llmResult
     * @param array<int, array<string, mixed>> $ragResults
     */
    public function detect(
        UnifiedMessageInterface $message,
        array $llmResult,
        array $ragResults,
        int $consecutiveClarifications,
        int $storeId
    ): ?string;

    /**
     * Transition the conversation to the escalated/hold state and notify the
     * operator. Called only after detect() returned a non-null reason.
     */
    public function escalate(ConversationInterface $conversation, string $reason, int $storeId = 0): void;
}
