<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Model\Analytics\UsageLogger;
use Zwernemann\Chat\Model\Llm\AnthropicClient;
use Zwernemann\Chat\Model\PipelineLogger;

class AnthropicClientTest extends TestCase
{
    private ScopeConfigInterface&MockObject $config;
    private Curl&MockObject                  $curl;
    private LoggerInterface&MockObject       $logger;
    private EncryptorInterface&MockObject    $encryptor;
    private PipelineLogger&MockObject        $pipelineLogger;
    private UsageLogger&MockObject           $usageLogger;

    private AnthropicClient $client;

    protected function setUp(): void
    {
        $this->config         = $this->createMock(ScopeConfigInterface::class);
        $this->curl           = $this->createMock(Curl::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->encryptor      = $this->createMock(EncryptorInterface::class);
        $this->pipelineLogger = $this->createMock(PipelineLogger::class);
        $this->usageLogger    = $this->createMock(UsageLogger::class);

        $this->encryptor->method('decrypt')->willReturnArgument(0);
        $this->config->method('getValue')->willReturnMap([
            ['zwernemann_chat/anthropic/api_key',   null, null, 'test-api-key'],
            ['zwernemann_chat/anthropic/model',     null, null, 'claude-sonnet-4-6'],
            ['zwernemann_chat/anthropic/max_tokens',null, null, '1024'],
        ]);

        $this->client = new AnthropicClient(
            $this->config,
            $this->curl,
            $this->logger,
            $this->encryptor,
            $this->pipelineLogger,
            $this->usageLogger
        );
    }

    private function stubCurlResponse(array $responseBody): void
    {
        $this->curl->method('getBody')->willReturn(json_encode($responseBody));
    }

    public function testChatReturnsFirstTextBlock(): void
    {
        $this->stubCurlResponse([
            'content' => [['type' => 'text', 'text' => 'Hello from Claude']],
            'usage'   => ['input_tokens' => 10, 'output_tokens' => 5],
        ]);

        $result = $this->client->chat([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('Hello from Claude', $result);
    }

    public function testChatThrowsOnMissingContent(): void
    {
        $this->stubCurlResponse(['content' => []]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no text content/i');

        $this->client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatThrowsWhenApiKeyEmpty(): void
    {
        $this->encryptor->method('decrypt')->willReturn('');
        $this->config->method('getValue')->willReturn('');

        $client = new AnthropicClient(
            $this->config,
            $this->curl,
            $this->logger,
            $this->encryptor,
            $this->pipelineLogger,
            $this->usageLogger
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/API key not configured/i');

        $client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testChatLogsUsageToUsageLogger(): void
    {
        $this->stubCurlResponse([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage'   => [
                'input_tokens'                   => 200,
                'output_tokens'                  => 100,
                'cache_creation_input_tokens'    => 50,
                'cache_read_input_tokens'        => 10,
            ],
        ]);

        $this->usageLogger->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $data): bool {
                return $data['provider'] === 'anthropic'
                    && $data['input_tokens'] === 200
                    && $data['output_tokens'] === 100
                    && $data['cache_write_tokens'] === 50
                    && $data['cache_read_tokens'] === 10
                    && isset($data['cost_usd']);
            }));

        $this->client->chat([['role' => 'user', 'content' => 'Hi']]);
    }

    public function testGetFastModelOptionsReturnsHaiku(): void
    {
        $this->config->method('getValue')
            ->with('zwernemann_chat/anthropic/fast_model')
            ->willReturn('claude-haiku-4-5-20251001');

        $opts = $this->client->getFastModelOptions();

        $this->assertSame('claude-haiku-4-5-20251001', $opts['model']);
        $this->assertSame(100, $opts['max_tokens']);
    }

    public function testChatWithToolReturnsToolInput(): void
    {
        $this->stubCurlResponse([
            'content' => [
                [
                    'type'  => 'tool_use',
                    'name'  => 'submit_intent',
                    'input' => ['intent' => 'order', 'confidence' => 0.95],
                ],
            ],
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ]);

        $result = $this->client->chatWithTool(
            [['role' => 'user', 'content' => 'Ich möchte 5 Stück SKU-001 bestellen']],
            'Du bist ein Assistent.',
            'submit_intent',
            ['type' => 'object', 'properties' => ['intent' => ['type' => 'string']]]
        );

        $this->assertSame('order', $result['intent'] ?? null);
        $this->assertSame(0.95, $result['confidence'] ?? null);
    }
}
