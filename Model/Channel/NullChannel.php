<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Channel;

use Zwernemann\Chat\Api\ChannelInterface;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;

/**
 * Fallback channel used when a message's channel type has no registered
 * channel implementation. WebChat answers synchronously via the HTTP response
 * (see Controller\Message\Send), so nothing needs to be pushed here.
 *
 * Premium channels (e-mail, …) register themselves in the MessageProcessor
 * channel map and therefore never fall back to this no-op.
 */
class NullChannel implements ChannelInterface
{
    public function getChannelType(): string
    {
        return 'null';
    }

    public function needsPlainText(): bool
    {
        return false;
    }

    /** @return UnifiedMessageInterface[] */
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
        // no-op: response already delivered synchronously by the channel controller
    }
}
