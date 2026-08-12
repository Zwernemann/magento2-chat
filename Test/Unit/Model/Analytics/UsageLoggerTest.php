<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Analytics;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Analytics\UsageLogger;

class UsageLoggerTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private UsageLogger $logger;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTableName')->willReturnArgument(0);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->logger = new UsageLogger($resourceConnection);
    }

    public function testLogInsertsRow(): void
    {
        $this->connection->expects($this->once())
            ->method('insert')
            ->with(
                'cc_llm_usage_log',
                $this->callback(function (array $row): bool {
                    return $row['provider'] === 'anthropic'
                        && $row['model'] === 'claude-sonnet-4-6'
                        && $row['input_tokens'] === 1000
                        && $row['output_tokens'] === 500
                        && $row['cost_usd'] === 0.010500;
                })
            );

        $this->logger->log([
            'provider'      => 'anthropic',
            'model'         => 'claude-sonnet-4-6',
            'input_tokens'  => 1000,
            'output_tokens' => 500,
            'cost_usd'      => 0.0105,
        ]);
    }

    public function testLogSilentlyIgnoresDbException(): void
    {
        $this->connection->method('insert')->willThrowException(new \Exception('DB error'));

        // Must not throw
        $this->logger->log(['provider' => 'anthropic', 'model' => 'test', 'cost_usd' => 0.0]);
        $this->assertTrue(true);
    }

    public function testLogRoundsCostToSixDecimals(): void
    {
        $capturedRow = null;
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$capturedRow) {
                $capturedRow = $row;
            });

        $this->logger->log(['provider' => 'mistral', 'model' => 'mistral-large-latest', 'cost_usd' => 0.00000049999]);

        $this->assertSame(0.000000, $capturedRow['cost_usd'] ?? null);
    }

    public function testLogSetsNullConversationIdWhenNotProvided(): void
    {
        $capturedRow = null;
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$capturedRow) {
                $capturedRow = $row;
            });

        $this->logger->log(['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001', 'cost_usd' => 0.0]);

        $this->assertNull($capturedRow['conversation_id'] ?? 'NOT_SET');
    }

    public function testLogSetsEmptyChannelTypeWhenNotProvided(): void
    {
        $capturedRow = null;
        $this->connection->method('insert')
            ->willReturnCallback(function (string $table, array $row) use (&$capturedRow) {
                $capturedRow = $row;
            });

        $this->logger->log(['provider' => 'anthropic', 'model' => 'claude-opus-4-7', 'cost_usd' => 0.0]);

        $this->assertSame('', $capturedRow['channel_type'] ?? 'NOT_SET');
    }
}
