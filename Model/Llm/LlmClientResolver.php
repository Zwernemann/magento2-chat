<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\LlmClientInterface;

/**
 * Proxy-Resolver: reads the configured LLM provider and delegates all interface calls
 * to the matching client from the injected pool.
 *
 * Folgt demselben Pool-Pattern wie der Channel-Map in MessageProcessor —
 * Provider werden per DI konfiguriert, kein if-then-Code im Business-Layer.
 */
class LlmClientResolver implements LlmClientInterface
{
    private const XML_PATH_PROVIDER = 'zwernemann_chat/llm/provider';
    private const DEFAULT_PROVIDER  = 'anthropic';

    /**
     * @param array<string, LlmClientInterface> $clients  Keyed by provider name
     */
    public function __construct(
        private readonly array                $clients,
        private readonly ScopeConfigInterface $config,
        private readonly LoggerInterface      $logger
    ) {}

    public function chat(array $messages, string $systemPrompt = '', array $options = []): string
    {
        return $this->getActiveClient()->chat($messages, $systemPrompt, $options);
    }

    public function chatWithTool(
        array  $messages,
        string $systemPrompt,
        string $toolName,
        array  $inputSchema,
        array  $options = [],
        array  $documentBlocks = []
    ): array {
        return $this->getActiveClient()->chatWithTool(
            $messages, $systemPrompt, $toolName, $inputSchema, $options, $documentBlocks
        );
    }

    public function chatJson(array $messages, string $systemPrompt = '', array $options = []): array
    {
        return $this->getActiveClient()->chatJson($messages, $systemPrompt, $options);
    }

    public function getFastModelOptions(): array
    {
        return $this->getActiveClient()->getFastModelOptions();
    }

    private function getActiveClient(): LlmClientInterface
    {
        $provider = (string)($this->config->getValue(self::XML_PATH_PROVIDER) ?? self::DEFAULT_PROVIDER);
        if (isset($this->clients[$provider])) {
            return $this->clients[$provider];
        }

        $this->logger->warning(
            '[LlmClientResolver] Unknown provider "' . $provider . '", falling back to "' . self::DEFAULT_PROVIDER . '".'
        );
        return $this->clients[self::DEFAULT_PROVIDER];
    }
}
