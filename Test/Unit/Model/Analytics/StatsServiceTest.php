<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Analytics;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Analytics\StatsService;

class StatsServiceTest extends TestCase
{
    private AdapterInterface&MockObject $connection;
    private StatsService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTableName')->willReturnArgument(0);

        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->getMock();
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $this->connection->method('select')->willReturn($select);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->service = new StatsService($resourceConnection);
    }

    public function testGetDashboardStatsReturnsExpectedKeys(): void
    {
        // All scalar queries return 0, array queries return empty
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAll')->willReturn([]);

        $stats = $this->service->getDashboardStats(30);

        $expectedKeys = [
            'period_days', 'total_conversations', 'converted_conversations',
            'conversion_rate', 'total_messages', 'clarification_messages',
            'clarification_rate', 'total_cost_usd', 'cost_per_message_usd',
            'cost_by_channel', 'cost_by_model',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $stats, "Missing key: $key");
        }
    }

    public function testConversionRateCalculation(): void
    {
        $callCount = 0;
        $this->connection->method('fetchOne')->willReturnCallback(
            function () use (&$callCount): string {
                $callCount++;
                return match ($callCount) {
                    1 => '100', // total conversations
                    2 => '25',  // converted conversations
                    3 => '200', // total messages
                    4 => '40',  // clarification messages
                    5 => '5.0', // total cost
                    default => '0',
                };
            }
        );
        $this->connection->method('fetchAll')->willReturn([]);

        $stats = $this->service->getDashboardStats(30);

        $this->assertSame(25.0, $stats['conversion_rate']);
    }

    public function testClarificationRateCalculation(): void
    {
        $callCount = 0;
        $this->connection->method('fetchOne')->willReturnCallback(
            function () use (&$callCount): string {
                $callCount++;
                return match ($callCount) {
                    1 => '50',  // total conversations
                    2 => '10',  // converted
                    3 => '200', // total messages
                    4 => '50',  // clarification messages
                    5 => '1.0', // total cost
                    default => '0',
                };
            }
        );
        $this->connection->method('fetchAll')->willReturn([]);

        $stats = $this->service->getDashboardStats(30);

        $this->assertSame(25.0, $stats['clarification_rate']);
    }

    public function testCostPerMessageIsZeroWhenNoMessages(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAll')->willReturn([]);

        $stats = $this->service->getDashboardStats(30);

        $this->assertSame(0.0, $stats['cost_per_message_usd']);
    }

    public function testCostByChannelGroupedCorrectly(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');
        $callCount = 0;
        $this->connection->method('fetchAll')->willReturnCallback(
            function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    // first fetchAll = cost by channel
                    return [
                        ['channel_type' => 'email',   'total' => '2.500000'],
                        ['channel_type' => 'webchat', 'total' => '1.250000'],
                    ];
                }
                return [];  // second fetchAll = cost by model
            }
        );

        $stats = $this->service->getDashboardStats(7);

        $this->assertSame(2.5, $stats['cost_by_channel']['email']);
        $this->assertSame(1.25, $stats['cost_by_channel']['webchat']);
    }

    public function testPeriodDaysIsPassedThrough(): void
    {
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchAll')->willReturn([]);

        $stats = $this->service->getDashboardStats(90);

        $this->assertSame(90, $stats['period_days']);
    }
}
