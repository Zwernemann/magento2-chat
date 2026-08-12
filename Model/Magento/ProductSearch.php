<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Magento;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\Shop\ProductLookupInterface;

/**
 * Resolves products and validates stock using native Magento repositories.
 *
 * Configurable products: the parent product entity has no own stock (qty always 0).
 * Stock is tracked on child simple products. getStockForSkus() detects configurable
 * parents via a single lightweight SQL type-check and delegates to resolveConfigurableStock()
 * which aggregates per-child stock and returns a 'variants' breakdown for ContextBuilder.
 */
class ProductSearch implements ProductLookupInterface
{
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SearchCriteriaBuilder      $searchCriteriaBuilder,
        private readonly StockRegistryInterface     $stockRegistry,
        private readonly ConfigurableType           $configurableType,
        private readonly ResourceConnection         $resourceConnection,
        private readonly LoggerInterface            $logger
    ) {}

    /** @return array<string, mixed>|null */
    public function getBySku(string $sku): ?array
    {
        try {
            return $this->toArray($this->productRepository->get($sku));
        } catch (NoSuchEntityException) {
            return null;
        }
    }

    /**
     * @param  string[] $skus
     * @return array<string, array<string, mixed>>  Keyed by SKU
     */
    public function getMultipleBySkus(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('sku', $skus, 'in')
            ->create();
        $result = [];
        foreach ($this->productRepository->getList($criteria)->getItems() as $product) {
            $result[$product->getSku()] = $this->toArray($product);
        }
        return $result;
    }

    /**
     * Returns live stock data for each SKU.
     *
     * For configurable products the parent stock item always shows qty=0 because
     * Magento tracks stock on child simple products. This method detects configurable
     * parents with a single lightweight SQL query (only sku + type_id columns) and
     * aggregates the children's stock, returning a 'variants' key with per-option detail.
     *
     * @param  string[] $skus
     * @return array<string, array{
     *     in_stock: bool,
     *     stock_qty: float|null,
     *     manage_stock: bool,
     *     variants?: list<array{option: string, sku: string, in_stock: bool, qty: float|null}>
     * }>  Keyed by SKU
     */
    public function getStockForSkus(array $skus): array
    {
        if (empty($skus)) {
            return [];
        }

        // One lightweight query to identify configurable products among the given SKUs.
        // Avoids loading full product data for type detection.
        $conn    = $this->resourceConnection->getConnection();
        $typeMap = $conn->fetchPairs(
            $conn->select()
                ->from($conn->getTableName('catalog_product_entity'), ['sku', 'type_id'])
                ->where('sku IN (?)', $skus)
        );

        $result = [];
        foreach ($skus as $sku) {
            try {
                if (($typeMap[$sku] ?? '') === 'configurable') {
                    $product      = $this->productRepository->get($sku);
                    $result[$sku] = $this->resolveConfigurableStock($product);
                } else {
                    $stock       = $this->stockRegistry->getStockItemBySku($sku);
                    $manageStock = (bool)$stock->getManageStock();
                    if (!$manageStock) {
                        $result[$sku] = [
                            'in_stock'     => true,
                            'stock_qty'    => null,
                            'manage_stock' => false,
                        ];
                    } else {
                        $result[$sku] = [
                            'in_stock'     => (bool)$stock->getIsInStock(),
                            'stock_qty'    => (float)$stock->getQty(),
                            'manage_stock' => true,
                        ];
                    }
                }
            } catch (\Throwable) {
                $result[$sku] = ['in_stock' => false, 'stock_qty' => 0.0, 'manage_stock' => true];
            }
        }
        return $result;
    }

    /**
     * @param  array<int, array{sku: string, qty: int}> $items
     * @return array<int, array{sku: string, qty: int, available: bool, stock_qty: float|null, manage_stock: bool}>
     */
    public function validateStock(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            try {
                $stock       = $this->stockRegistry->getStockItemBySku($item['sku']);
                $manageStock = (bool)$stock->getManageStock();
                $results[]   = [
                    'sku'          => $item['sku'],
                    'qty'          => $item['qty'],
                    'available'    => $manageStock ? (bool)$stock->getIsInStock() : true,
                    'stock_qty'    => $manageStock ? (float)$stock->getQty() : null,
                    'manage_stock' => $manageStock,
                ];
            } catch (\Throwable) {
                $results[] = ['sku' => $item['sku'], 'qty' => $item['qty'], 'available' => false, 'stock_qty' => 0.0, 'manage_stock' => true];
            }
        }
        return $results;
    }

    /**
     * Returns list price, customer-group-effective price, and applicable tier prices per SKU.
     *
     * In Magento 2, customer group prices are stored as tier price rows with qty = 1.
     * Group ID 32000 (Magento\Customer\Model\Group::CUST_GROUP_ALL) applies to every customer.
     *
     * @param  string[] $skus
     * @return array<string, array{list_price: float, group_price: float, tier_prices: list<array{qty: float, price: float}>}>
     */
    public function getPriceDataForSkus(array $skus, int $customerGroupId): array
    {
        if (empty($skus)) {
            return [];
        }
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('sku', $skus, 'in')
            ->create();
        $result = [];
        foreach ($this->productRepository->getList($criteria)->getItems() as $product) {
            $result[$product->getSku()] = $this->resolvePriceData($product, $customerGroupId);
        }
        return $result;
    }

    /**
     * Aggregates stock from child simple products of a configurable parent.
     *
     * Returns in_stock=true if at least one child is orderable, plus a 'variants' list
     * with per-option label, SKU, in_stock flag, and individual qty for each child.
     * stock_qty is the sum of all in-stock children's quantities.
     *
     * @return array{in_stock: bool, stock_qty: float, manage_stock: bool, variants: list<array{option: string, sku: string, in_stock: bool, qty: float|null}>}
     */
    private function resolveConfigurableStock(ProductInterface $product): array
    {
        $children = $this->configurableType->getUsedProducts($product);
        if (empty($children)) {
            return ['in_stock' => false, 'stock_qty' => 0.0, 'manage_stock' => true, 'variants' => []];
        }

        // Determine which attributes define variants (e.g. 'size', 'color')
        $attrCodes = array_column(
            $this->configurableType->getConfigurableAttributesAsArray($product),
            'attribute_code'
        );

        $variants   = [];
        $totalQty   = 0.0;
        $anyInStock = false;

        foreach ($children as $child) {
            $childSku = $child->getSku();

            // Build a human-readable option label like "XL" or "Blau / XL"
            $optionParts = [];
            foreach ($attrCodes as $code) {
                $text = $child->getAttributeText($code);
                if ($text !== false && $text !== null && (string)$text !== '') {
                    $optionParts[] = (string)$text;
                }
            }
            $label = $optionParts ? implode(' / ', $optionParts) : $childSku;

            try {
                $childStock   = $this->stockRegistry->getStockItemBySku($childSku);
                $childManage  = (bool)$childStock->getManageStock();
                $childInStock = $childManage ? (bool)$childStock->getIsInStock() : true;
                $childQty     = $childManage ? (float)$childStock->getQty() : null;
            } catch (\Throwable) {
                $childManage  = true;
                $childInStock = false;
                $childQty     = 0.0;
            }

            if ($childInStock) {
                $anyInStock = true;
                $totalQty  += $childQty ?? 0.0;
            }

            $variants[] = [
                'option'        => $label,
                'sku'           => $childSku,
                'in_stock'      => $childInStock,
                'qty'           => $childQty,
                'manage_stock'  => $childManage,
            ];
        }

        return [
            'in_stock'     => $anyInStock,
            'stock_qty'    => $totalQty,
            'manage_stock' => true,
            'variants'     => $variants,
        ];
    }

    /** @return array{list_price: float, group_price: float, tier_prices: list<array{qty: float, price: float}>} */
    private function resolvePriceData(ProductInterface $product, int $customerGroupId): array
    {
        $listPrice  = (float)$product->getPrice();
        $groupPrice = (float)$product->getFinalPrice(); // special_price + Catalog Price Rules
        $tierPrices = [];

        foreach ($product->getTierPrices() ?? [] as $tp) {
            $tpGroupId = (int)$tp->getCustomerGroupId();

            // Skip entries that don't apply to this customer's group or to all groups (32000)
            if ($tpGroupId !== $customerGroupId && $tpGroupId !== 32000) {
                continue;
            }

            $ext   = $tp->getExtensionAttributes();
            $pct   = $ext !== null ? (float)($ext->getPercentageValue() ?? 0) : 0.0;
            $price = $pct > 0
                ? round($listPrice * (1 - $pct / 100), 4)
                : (float)$tp->getValue();
            $qty   = (float)$tp->getQty();

            // Group price = most favourable price at qty ≤ 1 (how Magento stores group prices)
            if ($qty <= 1.0 && $price < $groupPrice) {
                $groupPrice = $price;
            }

            $tierPrices[] = ['qty' => $qty, 'price' => $price];
        }

        usort($tierPrices, static fn($a, $b) => $a['qty'] <=> $b['qty']);

        return [
            'list_price'  => $listPrice,
            'group_price' => $groupPrice,
            'tier_prices' => $tierPrices,
        ];
    }

    /** @return array<string, mixed> */
    private function toArray(ProductInterface $product): array
    {
        return [
            'id'    => (int)$product->getId(),
            'sku'   => $product->getSku(),
            'name'  => $product->getName(),
            'price' => (float)$product->getPrice(),
        ];
    }
}
