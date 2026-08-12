<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Message;

use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;

class UnifiedMessage implements UnifiedMessageInterface
{
    private string $channelType = 'email';
    private string $messageId   = '';
    private string $sessionId   = '';
    private string $customerIdentifier = '';
    private string $resolvedEmail      = '';
    private ?int   $magentoCustomerId  = null;
    private bool   $customerVerified   = false;
    private string $contentText        = '';
    private array  $attachments        = [];
    private array  $replyTo            = [];
    private array  $referenceMessageIds = [];
    private string $timestamp          = '';
    private int    $storeId            = 0;
    private bool   $isAutoReply        = false;

    public function getChannelType(): string        { return $this->channelType; }
    public function setChannelType(string $v): self { $this->channelType = $v; return $this; }

    public function getMessageId(): string        { return $this->messageId; }
    public function setMessageId(string $v): self { $this->messageId = $v; return $this; }

    public function getSessionId(): string        { return $this->sessionId; }
    public function setSessionId(string $v): self { $this->sessionId = $v; return $this; }

    public function getCustomerIdentifier(): string        { return $this->customerIdentifier; }
    public function setCustomerIdentifier(string $v): self { $this->customerIdentifier = $v; return $this; }

    public function getResolvedEmail(): string        { return $this->resolvedEmail; }
    public function setResolvedEmail(string $v): self { $this->resolvedEmail = $v; return $this; }

    public function getMagentoCustomerId(): ?int       { return $this->magentoCustomerId; }
    public function setMagentoCustomerId(?int $v): self { $this->magentoCustomerId = $v; return $this; }

    public function isCustomerVerified(): bool         { return $this->customerVerified; }
    public function setCustomerVerified(bool $v): self { $this->customerVerified = $v; return $this; }

    public function getContentText(): string        { return $this->contentText; }
    public function setContentText(string $v): self { $this->contentText = $v; return $this; }

    public function getAttachments(): array        { return $this->attachments; }
    public function setAttachments(array $v): self { $this->attachments = $v; return $this; }

    public function getReplyTo(): array        { return $this->replyTo; }
    public function setReplyTo(array $v): self { $this->replyTo = $v; return $this; }

    public function getReferenceMessageIds(): array        { return $this->referenceMessageIds; }
    public function setReferenceMessageIds(array $v): self { $this->referenceMessageIds = $v; return $this; }

    public function getTimestamp(): string        { return $this->timestamp; }
    public function setTimestamp(string $v): self { $this->timestamp = $v; return $this; }

    public function getStoreId(): int        { return $this->storeId; }
    public function setStoreId(int $v): self { $this->storeId = $v; return $this; }

    public function isAutoReply(): bool         { return $this->isAutoReply; }
    public function setAutoReply(bool $v): self { $this->isAutoReply = $v; return $this; }

    public function toArray(): array
    {
        return [
            'channelType' => $this->channelType,
            'messageId'   => $this->messageId,
            'sessionId'   => $this->sessionId,
            'customer'    => [
                'identifier' => $this->customerIdentifier,
                'magentoId'  => $this->magentoCustomerId,
                'verified'   => $this->customerVerified,
            ],
            'content'  => ['text' => $this->contentText, 'attachments' => $this->attachments],
            'replyTo'  => $this->replyTo,
            'timestamp' => $this->timestamp,
            'storeId'   => $this->storeId,
        ];
    }
}
