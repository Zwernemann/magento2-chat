<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ConversationMessage extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('cc_conversation_message', 'id');
    }

    public function messageIdExists(string $messageId): bool
    {
        $connection = $this->getConnection();
        $select     = $connection->select()
            ->from($this->getMainTable(), ['id'])
            ->where('message_id = ?', $messageId)
            ->limit(1);

        return (bool)$connection->fetchOne($select);
    }

    /**
     * Resolve the conversation a reply belongs to from the RFC threading chain.
     *
     * Matches the In-Reply-To / References Message-IDs of an inbound mail against the
     * stored message_id of any earlier message (inbound or outbound). The newest match
     * wins, so the reply re-attaches to the most recent referenced conversation even
     * when that conversation was already closed.
     *
     * @param string[] $messageIds Bare Message-IDs (no angle brackets)
     */
    public function findConversationIdByMessageIds(array $messageIds): ?int
    {
        $messageIds = array_values(array_filter(
            array_map('strval', $messageIds),
            static fn(string $v): bool => $v !== ''
        ));
        if (empty($messageIds)) {
            return null;
        }

        $connection = $this->getConnection();
        $select     = $connection->select()
            ->from($this->getMainTable(), ['conversation_id'])
            ->where('message_id IN (?)', $messageIds)
            ->order('id DESC')
            ->limit(1);

        $conversationId = $connection->fetchOne($select);

        return ($conversationId !== false && $conversationId !== null)
            ? (int)$conversationId
            : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    /**
     * @param  string      $order        'ASC' (oldest-first, default) or 'DESC' (newest-first)
     * @param  string|null $minCreatedAt When set, only messages with created_at >= this value
     *                                   are returned. Used to scope LLM/RAG context to the
     *                                   current topic (see Conversation::getTopicStartedAt);
     *                                   the webchat display and admin view pass null to keep
     *                                   showing the full thread.
     * @return array<int, array<string, mixed>>
     */
    public function getMessagesByConversationId(
        int $conversationId,
        int $limit = 20,
        string $order = 'ASC',
        ?string $minCreatedAt = null
    ): array {
        $connection = $this->getConnection();
        $select     = $connection->select()
            ->from($this->getMainTable())
            ->where('conversation_id = ?', $conversationId)
            ->order('created_at ' . ($order === 'DESC' ? 'DESC' : 'ASC'))
            ->limit($limit);

        if ($minCreatedAt !== null && $minCreatedAt !== '') {
            $select->where('created_at >= ?', $minCreatedAt);
        }

        return $connection->fetchAll($select);
    }
}
