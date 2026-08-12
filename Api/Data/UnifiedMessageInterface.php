<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api\Data;

/**
 * Unified Message Object – channel-agnostic representation of any inbound message.
 * Identical structure for email, WhatsApp, voice, SMS.
 */
interface UnifiedMessageInterface
{
    public function getChannelType(): string;
    public function setChannelType(string $channelType): self;

    public function getMessageId(): string;
    public function setMessageId(string $messageId): self;

    public function getSessionId(): string;
    public function setSessionId(string $sessionId): self;

    public function getCustomerIdentifier(): string;
    public function setCustomerIdentifier(string $identifier): self;

    /**
     * Resolved Magento customer email — set after successful customer lookup.
     * Always holds the primary email regardless of channel (email, WhatsApp, …).
     * Returns empty string if lookup has not run yet.
     */
    public function getResolvedEmail(): string;
    public function setResolvedEmail(string $email): self;

    public function getMagentoCustomerId(): ?int;
    public function setMagentoCustomerId(?int $id): self;

    public function isCustomerVerified(): bool;
    public function setCustomerVerified(bool $verified): self;

    public function getContentText(): string;
    public function setContentText(string $text): self;

    /** @return array<string, mixed> */
    public function getAttachments(): array;

    /** @param array<string, mixed> $attachments */
    public function setAttachments(array $attachments): self;

    /** @return array<string, mixed> */
    public function getReplyTo(): array;

    /** @param array<string, mixed> $replyTo */
    public function setReplyTo(array $replyTo): self;

    /**
     * RFC Message-IDs (bare, without angle brackets) of every message this one
     * references — the In-Reply-To value plus the full References chain. Used to
     * map an inbound reply back to the conversation it continues, even when that
     * conversation was already closed. Empty for channels without mail threading.
     *
     * @return string[]
     */
    public function getReferenceMessageIds(): array;

    /** @param string[] $messageIds */
    public function setReferenceMessageIds(array $messageIds): self;

    public function getTimestamp(): string;
    public function setTimestamp(string $timestamp): self;

    public function getStoreId(): int;
    public function setStoreId(int $storeId): self;

    public function isAutoReply(): bool;
    public function setAutoReply(bool $autoReply): self;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
