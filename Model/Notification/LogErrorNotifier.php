<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Notification;

use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\ErrorNotifierInterface;

/**
 * Default error notifier for the free Chat module: writes critical errors to
 * the module log only. The premium module overrides the preference with an
 * implementation that also sends throttled admin e-mails.
 */
class LogErrorNotifier implements ErrorNotifierInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {}

    public function notify(string $errorType, string $message, int $storeId = 0): void
    {
        $this->logger->error('[ErrorNotifier] ' . $errorType . ' – ' . $message, [
            'store_id' => $storeId,
        ]);
    }
}
