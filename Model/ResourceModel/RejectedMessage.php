<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Tracks inbound messages from unregistered senders that were already rejected.
 *
 * Rejected senders never get a conversation/message row, so the IMAP poller's
 * normal message_id de-duplication (against cc_conversation_message) does not catch
 * them — the same mail would be re-fetched and rejected on every poll cycle.
 * This table records each rejected message_id once, so the rejection notice is sent
 * exactly once per inbound mail.
 */
class RejectedMessage
{
    private const TABLE = 'cc_rejected_message';

    public function __construct(
        private readonly ResourceConnection $resource
    ) {}

    /** Has a rejection already been recorded for this message id? */
    public function exists(string $messageId): bool
    {
        if (trim($messageId) === '') {
            return false;
        }
        $conn = $this->resource->getConnection();
        $row  = $conn->fetchOne(
            'SELECT id FROM ' . $this->resource->getTableName(self::TABLE) . ' WHERE message_id = ? LIMIT 1',
            [$messageId]
        );
        return (bool)$row;
    }

    /**
     * Record that this message id was rejected. INSERT IGNORE keeps it idempotent
     * (and concurrency-safe) thanks to the unique index on message_id.
     */
    public function record(string $messageId, string $fromAddress): void
    {
        if (trim($messageId) === '') {
            return;
        }
        $conn = $this->resource->getConnection();
        $conn->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            ['message_id' => $messageId, 'from_address' => $fromAddress],
            ['from_address']
        );
    }
}
