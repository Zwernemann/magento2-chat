<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Magento;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\Shop\OrderHistoryInterface;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Fetches customer order history using the native OrderRepository.
 */
class OrderHistory implements OrderHistoryInterface
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder    $searchCriteriaBuilder,
        private readonly SortOrderBuilder         $sortOrderBuilder,
        private readonly LoggerInterface          $logger,
        private readonly PipelineLogger           $pipelineLogger
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function getByCustomerEmail(string $email, int $limit = 20, int $storeId = 0): array
    {
        $this->pipelineLogger->section('ORDER HISTORY QUERY');
        $this->pipelineLogger->data('Filter', ['customer_email' => $email, 'limit' => $limit, 'store_id' => $storeId, 'sort' => 'created_at DESC']);

        $sort    = $this->sortOrderBuilder->setField('created_at')->setDirection(SortOrder::SORT_DESC)->create();
        $builder = $this->searchCriteriaBuilder
            ->addFilter('customer_email', $email);
        if ($storeId > 0) {
            $builder->addFilter('store_id', $storeId);
        }
        $criteria = $builder->addSortOrder($sort)->setPageSize($limit)->create();

        $orders = array_map(
            [$this, 'toArray'],
            array_values($this->orderRepository->getList($criteria)->getItems())
        );

        $this->pipelineLogger->data('Orders found (' . count($orders) . ')', $orders);
        return $orders;
    }

    /**
     * Extended order history with date/status/SKU filters (for LLM tool_call get_order_history).
     *
     * @param array{date_from?: string|null, date_to?: string|null, status?: string|null, sku?: string|null, limit?: int, page?: int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function getByCustomerEmailFiltered(string $email, array $filters = [], int $storeId = 0): array
    {
        $limit = max(1, min(100, (int)($filters['limit'] ?? 20)));
        $page  = max(1, (int)($filters['page'] ?? 1));

        $sort    = $this->sortOrderBuilder->setField('created_at')->setDirection(SortOrder::SORT_DESC)->create();
        $builder = $this->searchCriteriaBuilder->addFilter('customer_email', $email);

        if ($storeId > 0) {
            $builder->addFilter('store_id', $storeId);
        }
        if (!empty($filters['date_from'])) {
            $builder->addFilter('created_at', $filters['date_from'] . ' 00:00:00', 'gteq');
        }
        if (!empty($filters['date_to'])) {
            $builder->addFilter('created_at', $filters['date_to'] . ' 23:59:59', 'lteq');
        }
        if (!empty($filters['status'])) {
            $builder->addFilter('status', $filters['status']);
        }

        $criteria = $builder->addSortOrder($sort)
            ->setPageSize($limit)
            ->setCurrentPage($page)
            ->create();

        $orders = array_map(
            [$this, 'toArray'],
            array_values($this->orderRepository->getList($criteria)->getItems())
        );

        // Post-filter by SKU if requested (not supported as a native order-level filter)
        if (!empty($filters['sku'])) {
            $skuFilter = strtolower(trim($filters['sku']));
            $orders = array_values(array_filter($orders, function (array $order) use ($skuFilter): bool {
                foreach ($order['items'] as $item) {
                    if (str_contains(strtolower($item['sku']), $skuFilter)) {
                        return true;
                    }
                }
                return false;
            }));
        }

        return $orders;
    }

    /** @return array<int, array<string, mixed>> */
    public function getByCustomerId(int $customerId, int $limit = 20, int $storeId = 0): array
    {
        $sort    = $this->sortOrderBuilder->setField('created_at')->setDirection(SortOrder::SORT_DESC)->create();
        $builder = $this->searchCriteriaBuilder->addFilter('customer_id', $customerId);
        if ($storeId > 0) {
            $builder->addFilter('store_id', $storeId);
        }
        $criteria = $builder->addSortOrder($sort)->setPageSize($limit)->create();

        return array_map(
            [$this, 'toArray'],
            array_values($this->orderRepository->getList($criteria)->getItems())
        );
    }

    /** @return array<string, mixed>|null */
    public function getOrderById(int $orderId): ?array
    {
        try {
            return $this->toArray($this->orderRepository->get($orderId));
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function toArray(OrderInterface $order): array
    {
        $items = [];
        foreach ($order->getItems() ?? [] as $item) {
            $items[] = [
                'sku'         => $item->getSku(),
                'name'        => $item->getName(),
                'qty_ordered' => (float)$item->getQtyOrdered(),
                'price'       => (float)$item->getPrice(),
            ];
        }
        return [
            'entity_id'      => (int)$order->getEntityId(),
            'increment_id'   => $order->getIncrementId(),
            'customer_email' => $order->getCustomerEmail(),
            'customer_id'    => $order->getCustomerId(),
            'created_at'     => $order->getCreatedAt(),
            'status'         => $order->getStatus(),
            'grand_total'    => (float)$order->getGrandTotal(),
            'items'          => $items,
        ];
    }

    /**
     * Aggregates order items by SKU across multiple orders.
     *
     * @param  array<int, array<string, mixed>> $orders
     * @return array<string, array{sku: string, name: string, total_qty: int, last_price: float}>
     */
    public function aggregateItemsBySku(array $orders, ?string $sinceDate = null): array
    {
        $aggregated = [];
        foreach ($orders as $order) {
            if ($sinceDate && substr($order['created_at'] ?? '', 0, 10) < $sinceDate) {
                continue;
            }
            foreach ($order['items'] ?? [] as $item) {
                $sku = $item['sku'] ?? '';
                if (!$sku) continue;
                if (!isset($aggregated[$sku])) {
                    $aggregated[$sku] = ['sku' => $sku, 'name' => $item['name'] ?? $sku, 'total_qty' => 0, 'last_price' => (float)($item['price'] ?? 0)];
                }
                $aggregated[$sku]['total_qty'] += (int)($item['qty_ordered'] ?? 1);
            }
        }
        return $aggregated;
    }
}
