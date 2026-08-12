<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api\Shop;

/**
 * Shop-system-agnostic interface for product catalogue and stock queries.
 */
interface ProductLookupInterface
{
    /** @return array<string, mixed>|null */
    public function getBySku(string $sku): ?array;

    /**
     * @param  string[] $skus
     * @return array<string, array<string, mixed>>  Keyed by SKU
     */
    public function getMultipleBySkus(array $skus): array;

    /**
     * Returns live stock data for each SKU, including variant breakdown for configurable products.
     *
     * @param  string[] $skus
     * @return array<string, array{in_stock: bool, stock_qty: float|null, manage_stock: bool, variants?: list<array{option: string, sku: string, in_stock: bool, qty: float|null}>}>
     */
    public function getStockForSkus(array $skus): array;

    /**
     * Returns list price, customer-group-effective price, and tier prices per SKU.
     *
     * @param  string[] $skus
     * @return array<string, array{list_price: float, group_price: float, tier_prices: list<array{qty: float, price: float}>}>
     */
    public function getPriceDataForSkus(array $skus, int $customerGroupId): array;

    /**
     * @param  array<int, array{sku: string, qty: int}> $items
     * @return array<int, array{sku: string, qty: int, available: bool, stock_qty: float|null, manage_stock: bool}>
     */
    public function validateStock(array $items): array;
}
