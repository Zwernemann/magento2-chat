<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Analytics;

use Magento\Framework\App\ResourceConnection;

/**
 * Aggregates dashboard KPIs from the conversation and usage-log tables.
 *
 * All queries are intentionally kept simple (raw SQL via Magento's connection)
 * so they work without any ORM overhead and can be inspected easily.
 */
class StatsService
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {}

    /**
     * Returns all KPIs needed for the admin dashboard in one call.
     *
     * @return array{
     *   period_days: int,
     *   total_conversations: int,
     *   converted_conversations: int,
     *   conversion_rate: float,
     *   total_messages: int,
     *   clarification_messages: int,
     *   clarification_rate: float,
     *   total_cost_usd: float,
     *   cost_per_message_usd: float,
     *   cost_by_channel: array<string, float>,
     *   cost_by_model: array<string, array{provider: string, model: string, calls: int, total_cost_usd: float, avg_cost_usd: float}>
     * }
     */
    public function getDashboardStats(int $days = 30): array
    {
        $conn  = $this->resourceConnection->getConnection();
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $convTable = $conn->getTableName('cc_conversation');
        $msgTable  = $conn->getTableName('cc_conversation_message');
        $logTable  = $conn->getTableName('cc_llm_usage_log');

        // Conversations in period
        $totalConversations = (int)$conn->fetchOne(
            $conn->select()->from($convTable, ['COUNT(*)'])->where('created_at >= ?', $since)
        );

        // Conversations that led to an order (at least one message with intent 'order' or 'reorder')
        $convertedConversations = (int)$conn->fetchOne(
            $conn->select()
                ->from(['c' => $convTable], ['COUNT(DISTINCT c.id)'])
                ->join(['m' => $msgTable], 'm.conversation_id = c.id', [])
                ->where('c.created_at >= ?', $since)
                ->where("m.intent IN ('order', 'reorder', 'create_order')")
        );

        // All messages in period (outbound only — each represents one LLM response)
        $totalMessages = (int)$conn->fetchOne(
            $conn->select()
                ->from(['m' => $msgTable], ['COUNT(*)'])
                ->join(['c' => $convTable], 'c.id = m.conversation_id', [])
                ->where('c.created_at >= ?', $since)
                ->where("m.direction = 'outbound'")
        );

        // Messages where the LLM asked for clarification
        $clarificationMessages = (int)$conn->fetchOne(
            $conn->select()
                ->from(['m' => $msgTable], ['COUNT(*)'])
                ->join(['c' => $convTable], 'c.id = m.conversation_id', [])
                ->where('c.created_at >= ?', $since)
                ->where("m.direction = 'outbound'")
                ->where("m.intent IN ('clarification', 'ask_clarification')")
        );

        // Total LLM cost in period
        $totalCostUsd = (float)$conn->fetchOne(
            $conn->select()->from($logTable, ['SUM(cost_usd)'])->where('created_at >= ?', $since)
        );

        // Cost per channel
        $costByChannelRows = $conn->fetchAll(
            $conn->select()
                ->from($logTable, ['channel_type', 'SUM(cost_usd) AS total'])
                ->where('created_at >= ?', $since)
                ->group('channel_type')
        );
        $costByChannel = [];
        foreach ($costByChannelRows as $row) {
            $costByChannel[$row['channel_type'] ?: 'unknown'] = round((float)$row['total'], 6);
        }

        // Cost breakdown by model
        $costByModelRows = $conn->fetchAll(
            $conn->select()
                ->from($logTable, [
                    'provider',
                    'model',
                    'COUNT(*) AS calls',
                    'SUM(cost_usd) AS total_cost',
                ])
                ->where('created_at >= ?', $since)
                ->group(['provider', 'model'])
                ->order('total_cost DESC')
        );
        $costByModel = [];
        foreach ($costByModelRows as $row) {
            $calls = max(1, (int)$row['calls']);
            $costByModel[] = [
                'provider'      => $row['provider'],
                'model'         => $row['model'],
                'calls'         => (int)$row['calls'],
                'total_cost_usd'=> round((float)$row['total_cost'], 6),
                'avg_cost_usd'  => round((float)$row['total_cost'] / $calls, 6),
            ];
        }

        $convRate  = $totalConversations > 0
            ? round($convertedConversations / $totalConversations * 100, 1)
            : 0.0;
        $clarRate  = $totalMessages > 0
            ? round($clarificationMessages / $totalMessages * 100, 1)
            : 0.0;
        $costPerMsg = $totalMessages > 0
            ? round($totalCostUsd / $totalMessages, 6)
            : 0.0;

        return [
            'period_days'            => $days,
            'total_conversations'    => $totalConversations,
            'converted_conversations'=> $convertedConversations,
            'conversion_rate'        => $convRate,
            'total_messages'         => $totalMessages,
            'clarification_messages' => $clarificationMessages,
            'clarification_rate'     => $clarRate,
            'total_cost_usd'         => round($totalCostUsd, 4),
            'cost_per_message_usd'   => $costPerMsg,
            'cost_by_channel'        => $costByChannel,
            'cost_by_model'          => $costByModel,
        ];
    }
}
