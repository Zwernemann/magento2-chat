<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Zwernemann\Chat\Api\Data\ConversationInterface;

class Conversation extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('cc_conversation', 'id');
    }

    /**
     * Loads the most recent ACTIVE conversation for a session. A session can now hold
     * several conversations over time (one per topic, see TopicResolver): closed topics
     * are set to 'resolved', so we exclude them and pick the newest remaining row. When
     * every conversation of the session is resolved, the caller creates a fresh one.
     */
    public function loadBySessionId(\Magento\Framework\Model\AbstractModel $object, string $sessionId, int $storeId = 0): self
    {
        $connection = $this->getConnection();
        $select     = $connection->select()
            ->from($this->getMainTable())
            ->where('session_id = ?', $sessionId)
            ->where('status != ?', ConversationInterface::STATUS_RESOLVED);

        if ($storeId > 0) {
            $select->where('store_id = ?', $storeId);
        }

        $select->order('id DESC')->limit(1);

        $data = $connection->fetchRow($select);
        if ($data) {
            $object->setData($data);
        }
        $this->_afterLoad($object);
        return $this;
    }
}
