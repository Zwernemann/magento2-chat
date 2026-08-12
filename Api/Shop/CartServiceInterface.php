<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Api\Shop;

/**
 * Shop-system-agnostic interface for cart management and order placement.
 *
 * All customer references use the internal numeric customer ID as resolved by CustomerProviderInterface.
 * All methods return associative arrays so callers are not tied to Magento model classes.
 */
interface CartServiceInterface
{
    /**
     * Create a new order directly from a list of items (without an existing cart).
     *
     * @param  array<int, array{sku: string, qty: int, options?: array<string,string>}> $items
     * @param  array<string, mixed> $customerData
     * @return array{success: bool, order_id: int|null, increment_id: string|null, error: string|null}
     */
    public function createOrder(
        int $customerId,
        array $items,
        array $customerData,
        string $poNumber = '',
        int $storeId = 0
    ): array;

    /**
     * Add items to the customer's active cart, creating the cart if necessary.
     *
     * @param  array<int, array{sku: string, qty: int, name?: string, options?: array<string,string>}> $items
     * @param  array<string, mixed> $customerData
     * @return array{success: bool, cart: array, error: string|null}
     */
    public function addItemsToCart(int $customerId, array $items, array $customerData, int $storeId = 0): array;

    /**
     * Update the quantity of a single item in the active cart.
     * Passing qty ≤ 0 removes the item.
     *
     * @return array{success: bool, cart: array, error: string|null}
     */
    public function updateCartItem(int $customerId, string $sku, int $qty, int $storeId = 0): array;

    /**
     * Remove an item from the active cart by SKU.
     *
     * @return array{success: bool, cart: array, error: string|null}
     */
    public function removeCartItem(int $customerId, string $sku, int $storeId = 0): array;

    /**
     * Place the customer's existing active cart as a new order.
     *
     * @param  array<string, mixed> $customerData
     * @return array{success: bool, order_id: int|null, increment_id: string|null, error: string|null}
     */
    public function checkoutCart(int $customerId, array $customerData, string $poNumber = '', int $storeId = 0): array;

    /**
     * Return the current cart contents without modifying it.
     *
     * @return array{items: list<array{sku: string, name: string, qty: int, price: float, row_total: float}>, subtotal: float, items_count: int}|array{}
     */
    public function getCartContents(int $customerId, int $storeId = 0): array;
}
