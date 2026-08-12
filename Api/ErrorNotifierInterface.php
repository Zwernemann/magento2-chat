<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api;

/**
 * Notifies operators about critical pipeline errors (LLM API failures, crashes).
 *
 * The free Chat module ships a log-only implementation. The premium module
 * overrides the preference with an implementation that also sends throttled
 * admin e-mails.
 */
interface ErrorNotifierInterface
{
    /**
     * Report a critical error. Implementations decide how to surface it
     * (log, e-mail, …) and are expected to throttle duplicate notifications.
     */
    public function notify(string $errorType, string $message, int $storeId = 0): void;
}
