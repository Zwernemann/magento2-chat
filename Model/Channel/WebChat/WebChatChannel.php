<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Channel\WebChat;

use Zwernemann\Chat\Api\ChannelInterface;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;

class WebChatChannel implements ChannelInterface
{
    public function getChannelType(): string
    {
        return 'webchat';
    }

    public function needsPlainText(): bool
    {
        // WebChat renders HTML only (cc-chat-widget.js uses response_html); the
        // plaintext used for history is derived from that HTML in PHP.
        return false;
    }

    public function pollMessages(): array
    {
        return [];
    }

    public function sendResponse(
        UnifiedMessageInterface $originalMessage,
        string $responseText,
        string $responseHtml,
        array $metadata = []
    ): void {
        // No-op: WebChat responses are returned synchronously via the HTTP response in Send.php
    }
}
