<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api\Data;

interface ConversationInterface
{
    public const STATUS_OPEN      = 'open';
    public const STATUS_RESOLVED  = 'resolved';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ESCALATED = 'escalated';

    public function getId(): ?int;

    public function getSessionId(): string;
    public function setSessionId(string $sessionId): self;

    public function getChannelType(): string;
    public function setChannelType(string $channelType): self;

    public function getCustomerEmail(): string;
    public function setCustomerEmail(string $email): self;

    public function getMagentoCustomerId(): ?int;
    public function setMagentoCustomerId(?int $id): self;

    public function getStatus(): string;
    public function setStatus(string $status): self;

    public function getSubject(): string;
    public function setSubject(string $subject): self;

    public function getTopicStartedAt(): ?string;
    public function setTopicStartedAt(?string $ts): self;

    public function getStoreId(): int;
    public function setStoreId(int $storeId): self;

    public function getCreatedAt(): string;
    public function getUpdatedAt(): string;

    public function getEscalationReason(): ?string;
    public function setEscalationReason(?string $reason): self;

    public function getEscalatedAt(): ?string;

    public function getEscalationApprovedAt(): ?string;
    public function setEscalationApprovedAt(?string $ts): self;

    public function getEscalationApprovedBy(): ?string;
    public function setEscalationApprovedBy(?string $adminEmail): self;
}
