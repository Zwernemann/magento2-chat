<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\LlmClientInterface;
use Zwernemann\Chat\Model\Analytics\UsageLogger;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Wrapper around the Anthropic Messages API.
 * Uses claude-sonnet-4-6 by default with prompt caching for system prompts.
 */
class AnthropicClient implements LlmClientInterface
{
    private const API_URL      = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION  = '2023-06-01';
    private const BETA_CACHING = 'prompt-caching-2024-07-31';

    private const XML_PATH_KEY        = 'zwernemann_chat/anthropic/api_key';
    private const XML_PATH_MODEL      = 'zwernemann_chat/anthropic/model';
    private const XML_PATH_MAX_TOKENS = 'zwernemann_chat/anthropic/max_tokens';
    private const XML_PATH_FAST_MODEL = 'zwernemann_chat/anthropic/fast_model';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly Curl                 $curl,
        private readonly LoggerInterface      $logger,
        private readonly EncryptorInterface   $encryptor,
        private readonly PipelineLogger       $pipelineLogger,
        private readonly UsageLogger          $usageLogger
    ) {}

    /**
     * Send a message to Claude and return the text response.
     *
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param string $systemPrompt  Cached by Anthropic on repeated calls
     * @param array<string, mixed>  $options  Override model/max_tokens/temperature
     */
    public function chat(array $messages, string $systemPrompt = '', array $options = []): string
    {
        $response = $this->callApi($messages, $systemPrompt, $options);

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                return $block['text'];
            }
        }

        $this->logger->error('Zwernemann_Chat: Anthropic API — no text block in response: ' . json_encode($response));
        throw new \RuntimeException('Anthropic API error: no text content in response.');
    }

    /**
     * Force structured JSON output via Anthropic tool_use.
     *
     * Anthropic guarantees the returned `input` matches the provided JSON Schema,
     * eliminating all client-side JSON parsing fragility.
     *
     * When $documentBlocks is non-empty, the last user message's content is converted
     * from a plain string to an array of Anthropic document blocks + a text block.
     * This enables native PDF understanding via the Anthropic document API.
     *
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param string $toolName      Name of the tool (e.g. "submit_response")
     * @param array<string, mixed>  $inputSchema  JSON Schema for the tool input
     * @param array<string, mixed>  $options
     * @param array<int, array{media_type: string, data: string}> $documentBlocks
     * @return array<string, mixed> The tool input dict
     */
    public function chatWithTool(
        array  $messages,
        string $systemPrompt,
        string $toolName,
        array  $inputSchema,
        array  $options = [],
        array  $documentBlocks = []
    ): array {
        if (!empty($documentBlocks)) {
            // Find the last user message and promote its content to an array
            // so Anthropic document blocks can be prepended before the text
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'user') {
                    $textContent = (string)$messages[$i]['content'];
                    $content     = [];
                    foreach ($documentBlocks as $block) {
                        $content[] = [
                            'type'   => 'document',
                            'source' => [
                                'type'       => 'base64',
                                'media_type' => $block['media_type'],
                                'data'       => $block['data'],
                            ],
                        ];
                    }
                    $content[]         = ['type' => 'text', 'text' => $textContent];
                    $messages[$i]['content'] = $content;
                    break;
                }
            }
        }

        $options['tools'] = [
            [
                'name'         => $toolName,
                'description'  => 'Submit your structured response using this tool.',
                'input_schema' => $inputSchema,
            ],
        ];
        $options['tool_choice'] = ['type' => 'tool', 'name' => $toolName];

        $response = $this->callApi($messages, $systemPrompt, $options);

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === $toolName) {
                return $block['input'] ?? [];
            }
        }

        $this->logger->error(
            'Zwernemann_Chat: chatWithTool — no tool_use block for "' . $toolName . '" in response: '
            . json_encode($response)
        );
        return [];
    }

    /**
     * Chat with structured JSON output (legacy — prefer chatWithTool for new calls).
     * Kept for backward compatibility with any remaining callers.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, string $systemPrompt = '', array $options = []): array
    {
        $text = $this->chat($messages, $systemPrompt, $options);

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $text = preg_replace('/\s*```$/m', '', $text);
        $text = trim($text);

        // Extract JSON object robustly: find first { and last }
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $text = substr($text, $start, $end - $start + 1);
        }

        // Also handle JSON arrays (for SearchTermExtractor fallback path)
        if ($text === '' || $text[0] !== '{') {
            $arrStart = strpos($text, '[');
            $arrEnd   = strrpos($text, ']');
            if ($arrStart !== false && $arrEnd !== false) {
                $text = substr($text, $arrStart, $arrEnd - $arrStart + 1);
            }
        }

        $data = json_decode($text, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $normalized = $this->normalizeJsonNewlines($text);
            $data       = json_decode($normalized, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->warning('Zwernemann_Chat: Non-JSON response from Claude: ' . $text);
                return ['raw' => $text];
            }
        }
        return $data;
    }

    public function getFastModelOptions(): array
    {
        $fastModel = (string)($this->config->getValue(self::XML_PATH_FAST_MODEL) ?? 'claude-haiku-4-5-20251001');
        return ['model' => $fastModel, 'max_tokens' => 100];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Make the HTTP call to the Anthropic Messages API and return the decoded response.
     *
     * @param array<string, mixed> $options  May include tools, tool_choice, temperature, model, max_tokens
     * @return array<string, mixed>
     */
    private function callApi(array $messages, string $systemPrompt, array $options = []): array
    {
        $apiKey    = trim($this->encryptor->decrypt($this->config->getValue(self::XML_PATH_KEY) ?? ''));
        $model     = $options['model']      ?? ($this->config->getValue(self::XML_PATH_MODEL) ?? 'claude-opus-4-7');
        $maxTokens = $options['max_tokens'] ?? (int)($this->config->getValue(self::XML_PATH_MAX_TOKENS) ?? 4096);

        if (empty($apiKey)) {
            throw new \RuntimeException('Zwernemann_Chat: Anthropic API key not configured.');
        }

        $payload = [
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => $messages,
        ];

        if (!empty($systemPrompt)) {
            $payload['system'] = [
                [
                    'type'          => 'text',
                    'text'          => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }
        if (isset($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        if (isset($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }

        $this->pipelineLogger->section('ANTHROPIC API CALL');
        $this->pipelineLogger->data('Model', $model);
        $this->pipelineLogger->data('Max tokens', $maxTokens);
        if (!empty($systemPrompt)) {
            $this->pipelineLogger->raw('System prompt', $systemPrompt);
        }
        $this->pipelineLogger->data('Messages', $messages);
        if (isset($options['tools'])) {
            $this->pipelineLogger->data('Tools', $options['tools']);
            $this->pipelineLogger->data('Tool choice', $options['tool_choice'] ?? null);
        }

        $this->curl->setHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::API_VERSION,
            'anthropic-beta'    => self::BETA_CACHING,
            'content-type'      => 'application/json',
        ]);
        // JSON_INVALID_UTF8_SUBSTITUTE keeps a single mis-encoded byte from making
        // json_encode() return false and POSTing an empty body ("zero-length document").
        $jsonPayload = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonPayload === false) {
            $this->logger->error('Zwernemann_Chat: Anthropic payload encoding failed – ' . json_last_error_msg());
            throw new \RuntimeException('Anthropic API error: request payload could not be encoded (' . json_last_error_msg() . ').');
        }
        $this->curl->post(self::API_URL, $jsonPayload);

        $body     = $this->curl->getBody();
        $this->pipelineLogger->addDebugBlock($model, $payload, $body);
        $this->pipelineLogger->raw('Raw HTTP response body', $body);
        $response = json_decode($body, true);

        if (!is_array($response) || !isset($response['content'])) {
            $this->logger->error('Zwernemann_Chat: Anthropic API error – ' . $body);
            throw new \RuntimeException('Anthropic API error: ' . ($response['error']['message'] ?? $body));
        }

        $usage = $response['usage'] ?? [];
        $costFloat = $this->estimateCostFloat($model, $usage);
        $this->pipelineLogger->data('Usage / cost', [
            'model'         => $model,
            'input_tokens'  => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cache_write'   => $usage['cache_creation_input_tokens'] ?? 0,
            'cache_read'    => $usage['cache_read_input_tokens'] ?? 0,
            'cost_estimate' => '$' . number_format($costFloat, 5),
        ]);
        $this->pipelineLogger->data('Response content blocks', $response['content'] ?? []);
        $this->logger->info('[LLM] Anthropic call', [
            'model'         => $model,
            'input_tokens'  => $usage['input_tokens'] ?? 0,
            'output_tokens' => $usage['output_tokens'] ?? 0,
            'cache_write'   => $usage['cache_creation_input_tokens'] ?? 0,
            'cache_read'    => $usage['cache_read_input_tokens'] ?? 0,
            'cost_estimate' => '$' . number_format($costFloat, 5),
        ]);
        $this->usageLogger->log([
            'provider'           => 'anthropic',
            'model'              => $model,
            'input_tokens'       => $usage['input_tokens'] ?? 0,
            'output_tokens'      => $usage['output_tokens'] ?? 0,
            'cache_write_tokens' => $usage['cache_creation_input_tokens'] ?? 0,
            'cache_read_tokens'  => $usage['cache_read_input_tokens'] ?? 0,
            'cost_usd'           => $costFloat,
        ]);

        return $response;
    }

    private function estimateCostFloat(string $model, array $usage): float
    {
        $prices = [
            'claude-sonnet-4-6'         => ['in' => 3.0,  'out' => 15.0,  'cw' => 3.75,  'cr' => 0.30],
            'claude-opus-4-7'           => ['in' => 15.0, 'out' => 75.0,  'cw' => 18.75, 'cr' => 1.50],
            'claude-haiku-4-5-20251001' => ['in' => 0.8,  'out' => 4.0,   'cw' => 1.0,   'cr' => 0.08],
        ];
        $p = $prices[$model] ?? $prices['claude-sonnet-4-6'];
        return (($usage['input_tokens'] ?? 0)                * $p['in']
              + ($usage['output_tokens'] ?? 0)               * $p['out']
              + ($usage['cache_creation_input_tokens'] ?? 0) * $p['cw']
              + ($usage['cache_read_input_tokens'] ?? 0)     * $p['cr']) / 1_000_000;
    }

    /**
     * Replace literal newline characters inside JSON string values with \n escape sequences.
     */
    private function normalizeJsonNewlines(string $text): string
    {
        $result   = '';
        $inString = false;
        $escape   = false;
        $len      = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $c = $text[$i];
            if ($escape) {
                $result .= $c;
                $escape  = false;
                continue;
            }
            if ($c === '\\' && $inString) {
                $result .= $c;
                $escape  = true;
                continue;
            }
            if ($c === '"') {
                $inString = !$inString;
                $result  .= $c;
                continue;
            }
            if ($inString && $c === "\r") {
                if (isset($text[$i + 1]) && $text[$i + 1] === "\n") {
                    $i++;
                }
                $result .= '\\n';
                continue;
            }
            if ($inString && $c === "\n") {
                $result .= '\\n';
                continue;
            }
            $result .= $c;
        }
        return $result;
    }
}
