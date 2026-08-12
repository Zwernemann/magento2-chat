<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api;

use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;

interface ChannelInterface
{
    public function getChannelType(): string;

    /**
     * Whether this channel needs a model-authored plaintext body.
     *
     * Channels that render a real text/plain representation (e-mail: the
     * multipart/alternative text part) return true, so the LLM is asked to
     * produce response_text alongside response_html. HTML-only channels
     * (WebChat) return false; their plaintext is derived from the HTML in PHP
     * instead of being generated a second time by the model — saving the
     * duplicated output tokens.
     */
    public function needsPlainText(): bool;

    /**
     * Poll for new inbound messages and return them as UnifiedMessage objects.
     *
     * @return UnifiedMessageInterface[]
     */
    public function pollMessages(): array;

    /**
     * Send a response back through this channel.
     *
     * @param UnifiedMessageInterface $originalMessage The message being replied to
     * @param string $responseText Plain-text response content
     * @param string $responseHtml HTML response content (may contain inline images)
     * @param array<string, mixed> $metadata Channel-specific metadata (thread ID, etc.)
     */
    public function sendResponse(
        UnifiedMessageInterface $originalMessage,
        string $responseText,
        string $responseHtml,
        array $metadata = []
    ): void;
}
