<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model;

use Magento\Framework\Model\AbstractModel;
use Zwernemann\Chat\Api\Data\ConversationInterface;

class Conversation extends AbstractModel implements ConversationInterface
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\Conversation::class);
    }

    public function getId(): ?int
    {
        $id = parent::getId();
        return $id !== null ? (int) $id : null;
    }

    public function getSessionId(): string        { return (string) $this->getData('session_id'); }
    public function setSessionId(string $v): self { return $this->setData('session_id', $v); }

    public function getChannelType(): string        { return (string) $this->getData('channel_type'); }
    public function setChannelType(string $v): self { return $this->setData('channel_type', $v); }

    public function getCustomerEmail(): string        { return (string) $this->getData('customer_email'); }
    public function setCustomerEmail(string $v): self { return $this->setData('customer_email', $v); }

    public function getMagentoCustomerId(): ?int
    {
        $v = $this->getData('magento_customer_id');
        return $v !== null ? (int) $v : null;
    }
    public function setMagentoCustomerId(?int $v): self { return $this->setData('magento_customer_id', $v); }

    public function getStatus(): string        { return (string) $this->getData('status'); }
    public function setStatus(string $v): self { return $this->setData('status', $v); }

    public function getSubject(): string        { return (string) $this->getData('subject'); }
    public function setSubject(string $v): self { return $this->setData('subject', $v); }

    public function getTopicStartedAt(): ?string
    {
        $v = $this->getData('topic_started_at');
        return $v !== null ? (string) $v : null;
    }
    public function setTopicStartedAt(?string $v): self { return $this->setData('topic_started_at', $v); }

    public function getStoreId(): int        { return (int) $this->getData('store_id'); }
    public function setStoreId(int $v): self { return $this->setData('store_id', $v); }

    public function getCreatedAt(): string { return (string) $this->getData('created_at'); }
    public function getUpdatedAt(): string { return (string) $this->getData('updated_at'); }

    public function getEscalationReason(): ?string
    {
        $v = $this->getData('escalation_reason');
        return $v !== null ? (string)$v : null;
    }
    public function setEscalationReason(?string $v): self { return $this->setData('escalation_reason', $v); }

    public function getEscalatedAt(): ?string
    {
        $v = $this->getData('escalated_at');
        return $v !== null ? (string)$v : null;
    }

    public function getEscalationApprovedAt(): ?string
    {
        $v = $this->getData('escalation_approved_at');
        return $v !== null ? (string)$v : null;
    }
    public function setEscalationApprovedAt(?string $v): self { return $this->setData('escalation_approved_at', $v); }

    public function getEscalationApprovedBy(): ?string
    {
        $v = $this->getData('escalation_approved_by');
        return $v !== null ? (string)$v : null;
    }
    public function setEscalationApprovedBy(?string $v): self { return $this->setData('escalation_approved_by', $v); }
}
