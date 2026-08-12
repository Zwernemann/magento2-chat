<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api;

interface LlmClientInterface
{
    /**
     * Send a chat message and return the plain-text response.
     *
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param array<string, mixed> $options  Provider-specific overrides (model, max_tokens, temperature)
     */
    public function chat(array $messages, string $systemPrompt = '', array $options = []): string;

    /**
     * Force structured JSON output via the provider's function/tool-calling mechanism.
     *
     * Each provider handles the wire format internally; callers always receive a plain PHP array
     * matching the provided $inputSchema — no JSON parsing required by the caller.
     *
     * $documentBlocks carries Anthropic-native PDF data (base64 + media_type). Providers that
     * do not support native document understanding (e.g. Mistral) silently ignore these blocks;
     * PDF text content should already be present as inline text via ContextBuilder.
     *
     * @param array<int, array{role: string, content: string|array}> $messages
     * @param array<string, mixed> $inputSchema  JSON Schema for the expected tool input
     * @param array<string, mixed> $options
     * @param array<int, array{media_type: string, data: string}> $documentBlocks
     * @return array<string, mixed>
     */
    public function chatWithTool(
        array  $messages,
        string $systemPrompt,
        string $toolName,
        array  $inputSchema,
        array  $options = [],
        array  $documentBlocks = []
    ): array;

    /**
     * Chat with structured JSON output (legacy path — prefer chatWithTool for new calls).
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, string $systemPrompt = '', array $options = []): array;

    /**
     * Returns the API call options for lightweight/fast model usage (e.g. query reformulation).
     *
     * Typical return: ['model' => '<fast-model-id>', 'max_tokens' => 100]
     * Each provider implementation reads its own fast_model config path.
     *
     * @return array<string, mixed>
     */
    public function getFastModelOptions(): array;
}
