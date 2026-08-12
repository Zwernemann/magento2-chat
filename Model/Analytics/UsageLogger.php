<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Analytics;

use Magento\Framework\App\ResourceConnection;

/**
 * Persists one row to cc_llm_usage_log after every LLM API call.
 * Designed to be injected into AnthropicClient and MistralClient.
 */
class UsageLogger
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {}

    /**
     * @param array{
     *   conversation_id?: int|null,
     *   channel_type?: string,
     *   provider: string,
     *   model: string,
     *   input_tokens?: int,
     *   output_tokens?: int,
     *   cache_write_tokens?: int,
     *   cache_read_tokens?: int,
     *   cost_usd: float
     * } $data
     */
    public function log(array $data): void
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $connection->insert(
                $connection->getTableName('cc_llm_usage_log'),
                [
                    'conversation_id'    => $data['conversation_id'] ?? null,
                    'channel_type'       => $data['channel_type'] ?? '',
                    'provider'           => $data['provider'],
                    'model'              => $data['model'],
                    'input_tokens'       => (int)($data['input_tokens'] ?? 0),
                    'output_tokens'      => (int)($data['output_tokens'] ?? 0),
                    'cache_write_tokens' => (int)($data['cache_write_tokens'] ?? 0),
                    'cache_read_tokens'  => (int)($data['cache_read_tokens'] ?? 0),
                    'cost_usd'           => round((float)($data['cost_usd'] ?? 0), 6),
                ]
            );
        } catch (\Throwable) {
            // Never let logging failures break the main pipeline
        }
    }
}
