<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api\Shop;

/**
 * Shop-system-agnostic interface for reading customer order history.
 */
interface OrderHistoryInterface
{
    /**
     * @return array<int, array<string, mixed>>  Most-recent orders first
     */
    public function getByCustomerEmail(string $email, int $limit = 20, int $storeId = 0): array;

    /**
     * Extended history with optional date/status/SKU filters (used by LLM tool get_order_history).
     *
     * @param array{date_from?: string|null, date_to?: string|null, status?: string|null, sku?: string|null, limit?: int, page?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getByCustomerEmailFiltered(string $email, array $filters = [], int $storeId = 0): array;
}
