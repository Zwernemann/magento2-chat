<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

use Magento\Framework\Model\AbstractModel;

class ConversationMessage extends AbstractModel
{
    public const DIRECTION_INBOUND  = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    protected function _construct(): void
    {
        $this->_init(ResourceModel\ConversationMessage::class);
    }

    public function getId(): ?int
    {
        $id = parent::getId();
        return $id !== null ? (int) $id : null;
    }

    public function getConversationId(): int  { return (int) $this->getData('conversation_id'); }
    public function getDirection(): string    { return (string) $this->getData('direction'); }
    public function getChannelType(): string  { return (string) $this->getData('channel_type'); }
    public function getMessageId(): string    { return (string) $this->getData('message_id'); }
    public function getContentText(): string  { return (string) $this->getData('content_text'); }
    public function getContentHtml(): string  { return (string) $this->getData('content_html'); }
    public function getIntent(): ?string      { $v = $this->getData('intent'); return $v ? (string)$v : null; }
    public function getCreatedAt(): string    { return (string) $this->getData('created_at'); }

    public function getIntentData(): array
    {
        $json = $this->getData('intent_data');
        if (!$json) return [];
        return json_decode($json, true) ?? [];
    }

    public function setIntentData(array $data): self
    {
        return $this->setData('intent_data', json_encode($data));
    }
}
