<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Pipeline;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\ProductAlert\Model\StockFactory as ProductAlertStockFactory;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CouponManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\CategoryListInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Wishlist\Model\WishlistFactory;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\Shop\CartServiceInterface;
use Zwernemann\Chat\Api\Shop\OrderHistoryInterface;

/**
 * Routes LLM tool_call entries to the corresponding Magento operations.
 *
 * Each execute() call returns:
 *   success         bool    — whether the operation succeeded
 *   response_text   string  — null = keep LLM response; set for read-ops
 *   response_html   string  — null = keep LLM response; set for read-ops
 *   error           string  — human-readable error (used when success=false)
 *
 * All operations are scoped to $customerData['id'] to prevent cross-customer access.
 */
class MagentoToolExecutor
{
    public function __construct(
        private readonly CartServiceInterface        $cartService,
        private readonly OrderHistoryInterface       $orderHistory,
        private readonly OrderRepositoryInterface    $orderRepository,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface  $addressRepository,
        private readonly AddressInterfaceFactory     $addressFactory,
        private readonly RegionInterfaceFactory      $regionInterfaceFactory,
        private readonly RegionFactory               $regionFactory,
        private readonly CouponManagementInterface   $couponManagement,
        private readonly CartRepositoryInterface     $cartRepository,
        private readonly SearchCriteriaBuilder       $searchCriteriaBuilder,
        private readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        private readonly SortOrderBuilder            $sortOrderBuilder,
        private readonly ProductRepositoryInterface  $productRepository,
        private readonly CategoryListInterface       $categoryList,
        private readonly StoreManagerInterface       $storeManager,
        private readonly ScopeConfigInterface        $scopeConfig,
        private readonly LoggerInterface             $logger,
        private readonly SubscriberFactory           $subscriberFactory,
        private readonly WishlistFactory             $wishlistFactory,
        private readonly ProductAlertStockFactory    $productAlertStockFactory,
        private readonly EavConfig                   $eavConfig
    ) {}

    /**
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $customerData
     * @return array{success: bool, response_text: string|null, response_html: string|null, error: string|null}
     */
    public function execute(string $toolName, array $params, array $customerData, int $storeId): array
    {
        $this->logger->info('[ToolExecutor] Executing tool', ['tool' => $toolName, 'params' => $params]);

        try {
            return match ($toolName) {
                'cart_add_item'             => $this->cartAdd($params, $customerData, $storeId),
                'cart_update_item'          => $this->cartUpdate($params, $customerData, $storeId),
                'cart_remove_item'          => $this->cartRemove($params, $customerData, $storeId),
                'cart_checkout'             => $this->cartCheckout($params, $customerData, $storeId),
                'reorder_from_history'      => $this->reorderFromHistory($params, $customerData, $storeId),
                'get_order_history'         => $this->getOrderHistory($params, $customerData, $storeId),
                'get_order_detail'          => $this->getOrderDetail($params, $customerData),
                'get_shipment_tracking'     => $this->getShipmentTracking($params, $customerData),
                'get_invoice'               => $this->getInvoice($params, $customerData, $storeId),
                'get_shipping_addresses'    => $this->getShippingAddresses($customerData),
                'add_shipping_address'      => $this->addShippingAddress($params, $customerData),
                'set_order_shipping_address'=> $this->setOrderShippingAddress($params, $customerData),
                'apply_coupon_code'         => $this->applyCouponCode($params, $customerData, $storeId),
                'remove_coupon_code'        => $this->removeCouponCode($customerData, $storeId),
                'get_account_info'          => $this->getAccountInfo($customerData),
                'update_account_info'       => $this->redirectToStore(['page' => 'account_edit'], $storeId),
                'toggle_newsletter'         => $this->toggleNewsletter($params, $customerData),
                'get_wishlist'              => $this->getWishlist($customerData, $storeId),
                'wishlist_add_item'         => $this->wishlistAddItem($params, $customerData, $storeId),
                'wishlist_remove_item'      => $this->wishlistRemoveItem($params, $customerData),
                'wishlist_move_to_cart'     => $this->wishlistMoveToCart($params, $customerData, $storeId),
                'search_products_by_filter' => $this->searchProductsByFilter($params, $storeId),
                'set_stock_notification'    => $this->setStockNotification($params, $customerData, $storeId),
                'redirect_to_store'         => $this->redirectToStore($params, $storeId),
                default                     => $this->unknownTool($toolName),
            };
        } catch (\Throwable $e) {
            $this->logger->error('[ToolExecutor] Tool execution failed', [
                'tool'  => $toolName,
                'error' => $e->getMessage(),
            ]);
            return $this->err('Technischer Fehler beim Ausführen der Aktion. Bitte versuchen Sie es erneut.');
        }
    }

    // ─── Cart Operations ────────────────────────────────────────────────────────

    private function cartAdd(array $params, array $customerData, int $storeId): array
    {
        $items  = $params['items'] ?? [];
        $result = $this->cartService->addItemsToCart((int)$customerData['id'], $items, $customerData, $storeId);
        if (!$result['success']) {
            return $this->err('Fehler beim Hinzufügen: ' . ($result['error'] ?? 'Unbekannt'));
        }
        $cartHtml   = $this->buildCartHtml($result['cart'] ?? []);
        $itemErrors = $result['item_errors'] ?? [];

        if (!empty($itemErrors)) {
            // Some items could not be added — tell the LLM so it can inform the user
            $errorLines = [];
            foreach ($itemErrors as $sku => $msg) {
                $errorLines[] = sprintf('SKU %s: %s', $sku, $msg);
            }
            $errorNote = implode('; ', $errorLines);
            return [
                'success'       => false,
                'response_text' => 'Einige Artikel konnten nicht zum Warenkorb hinzugefügt werden: ' . $errorNote,
                'response_html' => '<p>Einige Artikel konnten nicht zum Warenkorb hinzugefügt werden:</p><ul>'
                    . implode('', array_map(
                        fn($s, $m) => '<li>SKU ' . htmlspecialchars($s) . ': ' . htmlspecialchars($m) . '</li>',
                        array_keys($itemErrors),
                        $itemErrors
                    ))
                    . '</ul>' . $cartHtml,
                'error'         => $errorNote,
            ];
        }

        return [
            'success'       => true,
            'response_text' => 'Die Artikel wurden zum Warenkorb hinzugefügt.',
            'response_html' => '<p>Die Artikel wurden zum Warenkorb hinzugefügt.</p>' . $cartHtml,
            'error'         => null,
        ];
    }

    private function cartUpdate(array $params, array $customerData, int $storeId): array
    {
        $customerId = (int)$customerData['id'];
        $errors     = [];
        $lastCart   = [];
        foreach ($params['items'] ?? [] as $item) {
            $r = $this->cartService->updateCartItem($customerId, (string)$item['sku'], (int)$item['qty'], $storeId);
            if (!$r['success']) {
                $errors[] = $item['sku'] . ': ' . $r['error'];
            } else {
                $lastCart = $r['cart'];
            }
        }
        if (!empty($errors)) {
            return $this->err('Fehler: ' . implode('; ', $errors));
        }
        return [
            'success'       => true,
            'response_text' => 'Die Menge wurde aktualisiert.',
            'response_html' => '<p>Die Menge wurde im Warenkorb aktualisiert.</p>' . $this->buildCartHtml($lastCart),
            'error'         => null,
        ];
    }

    private function cartRemove(array $params, array $customerData, int $storeId): array
    {
        $customerId = (int)$customerData['id'];
        $errors     = [];
        $lastCart   = [];
        foreach ($params['items'] ?? [] as $item) {
            $r = $this->cartService->removeCartItem($customerId, (string)$item['sku'], $storeId);
            if (!$r['success']) {
                $errors[] = $item['sku'] . ': ' . $r['error'];
            } else {
                $lastCart = $r['cart'];
            }
        }
        if (!empty($errors)) {
            return $this->err('Fehler: ' . implode('; ', $errors));
        }
        return [
            'success'       => true,
            'response_text' => 'Der Artikel wurde aus dem Warenkorb entfernt.',
            'response_html' => '<p>Der Artikel wurde aus dem Warenkorb entfernt.</p>' . $this->buildCartHtml($lastCart),
            'error'         => null,
        ];
    }

    private function cartCheckout(array $params, array $customerData, int $storeId): array
    {
        $poNumber      = (string)($params['po_number'] ?? '');
        $paymentMethod = (string)($params['payment_method'] ?? '');
        $addressId     = isset($params['shipping_address_id']) ? (int)$params['shipping_address_id'] : null;
        $inlineAddress = $params['shipping_address'] ?? null;

        // Resolve inline address region if provided
        if ($inlineAddress !== null) {
            $inlineAddress = $this->resolveInlineAddressRegion($inlineAddress);
            $customerData['_inline_shipping_address'] = $inlineAddress;
        }
        if ($addressId !== null) {
            $customerData['_shipping_address_id'] = $addressId;
        }
        if ($paymentMethod !== '') {
            $customerData['_payment_method'] = $paymentMethod;
        }
        if (!empty($params['vault_payment_token'])) {
            $customerData['_vault_payment_token'] = $params['vault_payment_token'];
        }

        $result = $this->cartService->checkoutCart((int)$customerData['id'], $customerData, $poNumber, $storeId);

        if (!$result['success'] && ($result['error'] ?? '') === 'needs_po_number') {
            return [
                'success'       => false,
                'response_text' => 'Für Ihre Bestellung benötigen wir eine Bestellnummer (Purchase Order Number / PO-Nummer). Bitte teilen Sie mir Ihre PO-Nummer mit.',
                'response_html' => '<p>Für Ihre Bestellung benötigen wir eine <strong>Bestellnummer (PO-Nummer)</strong>. Bitte teilen Sie mir Ihre PO-Nummer mit.</p>',
                'error'         => 'needs_po_number',
            ];
        }

        if ($result['success']) {
            $ref = $result['increment_id'] ?? (string)($result['order_id'] ?? '');
            return [
                'success'       => true,
                'increment_id'  => $ref,
                'response_text' => sprintf("Ihre Bestellung #%s wurde erfolgreich aufgegeben.\n\nSie erhalten in Kürze eine Auftragsbestätigung per E-Mail.", $ref),
                'response_html' => sprintf(
                    '<p>Ihre Bestellung <strong>#%s</strong> wurde erfolgreich aufgegeben.</p><p>Sie erhalten in Kürze eine Auftragsbestätigung per E-Mail.</p>',
                    htmlspecialchars($ref)
                ),
                'error'         => null,
            ];
        }

        return $this->err('Die Bestellung konnte nicht aufgegeben werden: ' . ($result['error'] ?? 'Unbekannt'));
    }

    // ─── Order Operations ───────────────────────────────────────────────────────

    private function reorderFromHistory(array $params, array $customerData, int $storeId): array
    {
        $incrementId = (string)($params['order_increment_id'] ?? '');
        if ($incrementId === '') {
            return $this->err('Bitte geben Sie eine Bestellnummer an.');
        }

        // Find the order by increment_id for this customer
        $orders = $this->orderHistory->getByCustomerEmail(
            (string)$customerData['email'], 100, $storeId
        );
        $order = null;
        foreach ($orders as $o) {
            if ($o['increment_id'] === $incrementId) {
                $order = $o;
                break;
            }
        }

        if ($order === null) {
            return $this->err("Bestellung #{$incrementId} wurde nicht gefunden oder gehört nicht zu Ihrem Konto.");
        }

        $items = array_map(fn($i) => [
            'sku'  => $i['sku'],
            'qty'  => max(1, (int)$i['qty_ordered']),
            'name' => $i['name'] ?? $i['sku'],
        ], $order['items'] ?? []);

        if (empty($items)) {
            return $this->err("Bestellung #{$incrementId} enthält keine Artikel.");
        }

        $result = $this->cartService->addItemsToCart((int)$customerData['id'], $items, $customerData, $storeId);
        if (!$result['success']) {
            return $this->err('Fehler beim Laden der Bestellung: ' . ($result['error'] ?? 'Unbekannt'));
        }

        $cartHtml = $this->buildCartHtml($result['cart'] ?? []);
        return [
            'success'       => true,
            'response_text' => "Die Artikel aus Bestellung #{$incrementId} wurden in Ihren Warenkorb gelegt.",
            'response_html' => "<p>Die Artikel aus Bestellung <strong>#{$incrementId}</strong> wurden in Ihren Warenkorb gelegt.</p>" . $cartHtml,
            'error'         => null,
        ];
    }

    private function getOrderHistory(array $params, array $customerData, int $storeId): array
    {
        $filters = [
            'date_from' => $params['date_from'] ?? null,
            'date_to'   => $params['date_to']   ?? null,
            'status'    => $params['status']    ?? null,
            'sku'       => $params['sku']        ?? null,
            'limit'     => min((int)($params['limit'] ?? 20), 100),
            'page'      => max(1, (int)($params['page'] ?? 1)),
        ];

        $orders = $this->orderHistory->getByCustomerEmailFiltered(
            (string)$customerData['email'], $filters, $storeId
        );

        if (empty($orders)) {
            $msg = 'Für den angegebenen Zeitraum wurden keine Bestellungen gefunden.';
            return ['success' => true, 'response_text' => $msg, 'response_html' => '<p>' . $msg . '</p>', 'error' => null];
        }

        $lines = ['<p><strong>Gefundene Bestellungen:</strong></p><table><thead><tr><th>Bestellnr.</th><th>Datum</th><th>Gesamt</th><th>Status</th></tr></thead><tbody>'];
        $plain = [];
        foreach ($orders as $o) {
            $date  = substr($o['created_at'] ?? '', 0, 10);
            $total = number_format((float)($o['grand_total'] ?? 0), 2, ',', '.');
            $lines[] = sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s EUR</td><td>%s</td></tr>',
                htmlspecialchars($o['increment_id'] ?? ''), $date, $total,
                htmlspecialchars($o['status'] ?? '')
            );
            $plain[] = sprintf('#%s vom %s – %s EUR – %s',
                $o['increment_id'] ?? '', $date, $total, $o['status'] ?? '');
        }
        $lines[] = '</tbody></table>';

        return [
            'success'       => true,
            'response_text' => implode("\n", $plain),
            'response_html' => implode('', $lines),
            'error'         => null,
        ];
    }

    private function getOrderDetail(array $params, array $customerData): array
    {
        $incrementId = (string)($params['order_increment_id'] ?? '');
        $order       = $this->findOrderForCustomer($incrementId, (string)$customerData['email']);
        if ($order === null) {
            return $this->err("Bestellung #{$incrementId} wurde nicht gefunden.");
        }

        $date  = substr($order['created_at'] ?? '', 0, 10);
        $total = number_format((float)($order['grand_total'] ?? 0), 2, ',', '.');

        $rows  = '';
        $plain = ["Bestellung #{$order['increment_id']} vom {$date}", "Status: {$order['status']}", "Gesamt: {$total} EUR", '', 'Positionen:'];
        foreach ($order['items'] ?? [] as $item) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%d</td><td>%.2f EUR</td></tr>',
                htmlspecialchars($item['name'] ?? ''), htmlspecialchars($item['sku'] ?? ''),
                (int)$item['qty_ordered'], (float)$item['price']
            );
            $plain[] = sprintf('  • %s (SKU: %s) – %d Stück à %.2f EUR',
                $item['name'] ?? '', $item['sku'] ?? '', (int)$item['qty_ordered'], (float)$item['price']);
        }

        $html = sprintf(
            '<p><strong>Bestellung #%s</strong> vom %s<br>Status: <em>%s</em> | Gesamt: <strong>%s EUR</strong></p>'
            . '<table><thead><tr><th>Produkt</th><th>SKU</th><th>Menge</th><th>Preis</th></tr></thead><tbody>%s</tbody></table>',
            htmlspecialchars($order['increment_id'] ?? ''), $date,
            htmlspecialchars($order['status'] ?? ''), $total, $rows
        );

        return ['success' => true, 'response_text' => implode("\n", $plain), 'response_html' => $html, 'error' => null];
    }

    private function getShipmentTracking(array $params, array $customerData): array
    {
        $incrementId = (string)($params['order_increment_id'] ?? '');
        $order       = $this->findOrderForCustomer($incrementId, (string)$customerData['email']);
        if ($order === null) {
            return $this->err("Bestellung #{$incrementId} wurde nicht gefunden.");
        }

        try {
            $criteria  = $this->searchCriteriaBuilder
                ->addFilter('order_id', $order['entity_id'])
                ->create();
            $shipments = $this->shipmentRepository->getList($criteria)->getItems();
        } catch (\Throwable $e) {
            return $this->err('Versandinformationen konnten nicht geladen werden.');
        }

        if (empty($shipments)) {
            $msg = "Für Bestellung #{$incrementId} liegen noch keine Versandinformationen vor.";
            return ['success' => true, 'response_text' => $msg, 'response_html' => '<p>' . htmlspecialchars($msg) . '</p>', 'error' => null];
        }

        $plain = ["Versand für Bestellung #{$incrementId}:"];
        $html  = "<p><strong>Versand für Bestellung #{$incrementId}:</strong></p>";

        foreach ($shipments as $shipment) {
            $tracks = $shipment->getTracks() ?? [];
            foreach ($tracks as $track) {
                $carrier    = htmlspecialchars($track->getTitle() ?? 'Carrier');
                $trackNum   = htmlspecialchars($track->getTrackNumber() ?? '');
                $plain[]    = "  Carrier: {$carrier} | Tracking-Nr.: {$trackNum}";
                $html      .= "<p>Carrier: <strong>{$carrier}</strong> | Tracking: <strong>{$trackNum}</strong></p>";
            }
            if (empty($tracks)) {
                $plain[] = '  Sendung versandt, kein Tracking verfügbar.';
                $html   .= '<p><em>Sendung versandt, kein Tracking verfügbar.</em></p>';
            }
        }

        return ['success' => true, 'response_text' => implode("\n", $plain), 'response_html' => $html, 'error' => null];
    }

    private function getInvoice(array $params, array $customerData, int $storeId): array
    {
        $incrementId = (string)($params['order_increment_id'] ?? '');
        $storeUrl    = rtrim($this->storeManager->getStore($storeId ?: null)->getBaseUrl(), '/');
        $order       = $this->findOrderForCustomer($incrementId, (string)$customerData['email']);
        if ($order === null) {
            return $this->err("Bestellung #{$incrementId} wurde nicht gefunden.");
        }

        $link = $storeUrl . '/sales/order/view/order_id/' . $order['entity_id'];
        $msg  = "Ihre Rechnung zu Bestellung #{$incrementId} finden Sie in Ihrem Kundenkonto: {$link}";
        $html = "<p>Ihre Rechnung zu Bestellung <strong>#{$incrementId}</strong> finden Sie in Ihrem Kundenkonto: "
              . "<a href=\"{$link}\">Zur Bestellung</a></p>";

        return ['success' => true, 'response_text' => $msg, 'response_html' => $html, 'error' => null];
    }

    // ─── Address Operations ─────────────────────────────────────────────────────

    private function getShippingAddresses(array $customerData): array
    {
        $addresses = $customerData['addresses'] ?? [];
        if (empty($addresses)) {
            $msg = 'Sie haben noch keine Adressen in Ihrem Konto hinterlegt.';
            return ['success' => true, 'response_text' => $msg, 'response_html' => '<p>' . $msg . '</p>', 'error' => null];
        }

        $plain = ['Ihre hinterlegten Lieferadressen:'];
        $html  = '<p><strong>Ihre hinterlegten Lieferadressen:</strong></p><ul>';
        foreach ($addresses as $addr) {
            $id      = $addr['id'] ?? '?';
            $street  = is_array($addr['street'] ?? '') ? implode(', ', $addr['street']) : ($addr['street'] ?? '');
            $line    = sprintf('ID %s: %s %s, %s %s, %s%s',
                $id,
                $addr['firstname'] ?? '', $addr['lastname'] ?? '',
                $street,
                $addr['postcode'] ?? '', $addr['city'] ?? '',
                ($addr['default_shipping'] ?? false) ? ' (Standard-Lieferadresse)' : ''
            );
            $plain[] = '  ' . $line;
            $html   .= '<li>' . htmlspecialchars($line) . '</li>';
        }
        $html .= '</ul>';

        return ['success' => true, 'response_text' => implode("\n", $plain), 'response_html' => $html, 'error' => null];
    }

    private function addShippingAddress(array $params, array $customerData): array
    {
        try {
            $customer = $this->customerRepository->getById((int)$customerData['id']);
            $address  = $this->addressFactory->create();

            $address->setCustomerId((int)$customerData['id'])
                ->setFirstname($params['firstname'] ?? ($customerData['firstname'] ?? ''))
                ->setLastname($params['lastname']  ?? ($customerData['lastname']  ?? ''))
                ->setStreet([$params['street'] ?? ''])
                ->setPostcode($params['postcode'] ?? '')
                ->setCity($params['city'] ?? '')
                ->setCountryId($params['country_id'] ?? 'DE')
                ->setTelephone($params['telephone'] ?? '');

            // Auto-resolve region from postcode
            $regionId = $this->resolveRegionIdByPostcode($params['postcode'] ?? '', $params['country_id'] ?? 'DE');
            if ($regionId > 0) {
                $region = $this->regionInterfaceFactory->create();
                $region->setRegionId($regionId);
                $address->setRegion($region)->setRegionId($regionId);
            }

            if (!empty($params['set_as_default'])) {
                $address->setIsDefaultShipping(true);
            }

            $saved = $this->addressRepository->save($address);

            $msg  = sprintf('Die Adresse %s %s, %s %s wurde gespeichert.',
                $params['firstname'] ?? '', $params['lastname'] ?? '',
                $params['postcode'] ?? '', $params['city'] ?? '');
            $html = '<p>Die neue Adresse wurde erfolgreich gespeichert.</p>';

            return ['success' => true, 'response_text' => $msg, 'response_html' => $html, 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('[ToolExecutor] addShippingAddress failed: ' . $e->getMessage());
            return $this->err('Die Adresse konnte nicht gespeichert werden: ' . $e->getMessage());
        }
    }

    private function setOrderShippingAddress(array $params, array $customerData): array
    {
        $incrementId = (string)($params['order_increment_id'] ?? '');
        $order       = $this->findOrderForCustomer($incrementId, (string)$customerData['email']);
        if ($order === null) {
            return $this->err("Bestellung #{$incrementId} nicht gefunden.");
        }
        // Only pending/processing orders can be modified
        if (!in_array($order['status'] ?? '', ['pending', 'processing', 'pending_payment'], true)) {
            return $this->err("Die Lieferadresse kann nur bei Bestellungen im Status 'Ausstehend' oder 'In Bearbeitung' geändert werden.");
        }

        $storeUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        $link     = $storeUrl . '/sales/order/view/order_id/' . $order['entity_id'];
        $msg      = "Adressänderungen an bestehenden Bestellungen sind im Shop möglich: {$link}";
        $html     = "<p>Adressänderungen an bestehenden Bestellungen können Sie direkt im Shop vornehmen: <a href=\"{$link}\">Zur Bestellung</a></p>";

        return ['success' => true, 'response_text' => $msg, 'response_html' => $html, 'error' => null];
    }

    // ─── Coupon Operations ─────────────────────────────────────────────────────

    private function applyCouponCode(array $params, array $customerData, int $storeId): array
    {
        $couponCode = trim((string)($params['coupon_code'] ?? ''));
        if ($couponCode === '') {
            return $this->err('Bitte geben Sie einen Gutscheincode an.');
        }

        $cartId = $this->getActiveCartId((int)$customerData['id'], $storeId);
        if ($cartId === null) {
            return $this->err('Sie haben keinen aktiven Warenkorb. Bitte fügen Sie zuerst Artikel hinzu.');
        }

        try {
            $this->couponManagement->set($cartId, $couponCode);
            $cart     = $this->cartRepository->get($cartId);
            $discount = number_format((float)abs($cart->getShippingAddress()?->getDiscountAmount() ?? 0), 2, ',', '.');
            $msg      = "Gutscheincode \"{$couponCode}\" wurde angewendet. Rabatt: {$discount} EUR";
            $html     = "<p>Gutscheincode <strong>{$couponCode}</strong> wurde angewendet. Rabatt: <strong>{$discount} EUR</strong></p>";
            return ['success' => true, 'response_text' => $msg, 'response_html' => $html, 'error' => null];
        } catch (\Throwable $e) {
            return $this->err("Gutscheincode \"{$couponCode}\" ist ungültig oder nicht anwendbar.");
        }
    }

    private function removeCouponCode(array $customerData, int $storeId): array
    {
        $cartId = $this->getActiveCartId((int)$customerData['id'], $storeId);
        if ($cartId === null) {
            return $this->err('Kein aktiver Warenkorb gefunden.');
        }
        try {
            $this->couponManagement->remove($cartId);
            $msg = 'Der Gutscheincode wurde entfernt.';
            return ['success' => true, 'response_text' => $msg, 'response_html' => '<p>' . $msg . '</p>', 'error' => null];
        } catch (\Throwable $e) {
            return $this->err('Gutscheincode konnte nicht entfernt werden.');
        }
    }

    // ─── Account ─────────────────────────────────────────────────────────────

    private function getAccountInfo(array $customerData): array
    {
        $name  = trim(($customerData['firstname'] ?? '') . ' ' . ($customerData['lastname'] ?? ''));
        $email = $customerData['email'] ?? '';
        $co    = $customerData['company'] ?? '';
        $group = $customerData['group_name'] ?? '';

        $lines = ["Name: {$name}", "E-Mail: {$email}"];
        if ($co) {
            $lines[] = "Firma: {$co}";
        }
        if ($group) {
            $lines[] = "Kundengruppe: {$group}";
        }

        $html = '<p>' . implode('<br>', array_map('htmlspecialchars', $lines)) . '</p>';
        return ['success' => true, 'response_text' => implode("\n", $lines), 'response_html' => $html, 'error' => null];
    }

    // ─── Product Search ──────────────────────────────────────────────────────

    private function searchProductsByFilter(array $params, int $storeId): array
    {
        try {
            $pageSize = min((int)($params['page_size'] ?? 20), 50);
            $page     = max(1, (int)($params['page'] ?? 1));

            $builder = $this->searchCriteriaBuilder
                ->addFilter('status', 1)
                ->addFilter('visibility', [2, 3, 4], 'in');

            if (!empty($params['in_stock']) && $params['in_stock']) {
                $builder->addFilter('quantity_and_stock_status', 1);
            }

            if (!empty($params['category_name'])) {
                $catId = $this->resolveCategoryId($params['category_name'], $storeId);
                if ($catId) {
                    $builder->addFilter('category_id', $catId);
                }
            }

            if (!empty($params['attribute_code']) && isset($params['attribute_value']) && $params['attribute_value'] !== '') {
                // Strip attr_ prefix added by the ProductIndexer for Pinecone metadata keys
                $attrCode  = $params['attribute_code'];
                if (str_starts_with($attrCode, 'attr_')) {
                    $attrCode = substr($attrCode, 5);
                }
                $attrValue = (string)$params['attribute_value'];
                // Map boolean label strings to integer values
                if (in_array(strtolower($attrValue), ['nein', 'no', 'false'], true)) {
                    $builder->addFilter($attrCode, '0');
                } elseif (in_array(strtolower($attrValue), ['ja', 'yes', 'true'], true)) {
                    $builder->addFilter($attrCode, '1');
                } else {
                    // For select/dropdown attributes: resolve label to option ID.
                    // For text attributes: fall back to LIKE.
                    $resolvedId = $this->resolveDropdownOptionId($attrCode, $attrValue);
                    if ($resolvedId !== null) {
                        $builder->addFilter($attrCode, $resolvedId);
                    } else {
                        $builder->addFilter($attrCode, '%' . $attrValue . '%', 'like');
                    }
                }
            }

            $sortField = $params['sort_by'] ?? 'name';
            $sortDir   = strtoupper($params['sort_direction'] ?? 'ASC') === 'DESC'
                ? SortOrder::SORT_DESC
                : SortOrder::SORT_ASC;

            $sort     = $this->sortOrderBuilder->setField($sortField)->setDirection($sortDir)->create();
            $criteria = $builder->addSortOrder($sort)->setPageSize($pageSize)->setCurrentPage($page)->create();

            $results = $this->productRepository->getList($criteria);
            $items   = $results->getItems();

            if (empty($items)) {
                $msg = 'Es wurden keine passenden Produkte gefunden.';
                return ['success' => true, 'response_text' => $msg, 'response_html' => '<p>' . $msg . '</p>', 'error' => null];
            }

            $mediaUrl = rtrim(
                $this->storeManager->getStore($storeId ?: null)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA),
                '/'
            );

            $cards = '';
            $plain = ['Gefundene Produkte:'];
            foreach ($items as $product) {
                $price    = number_format((float)$product->getFinalPrice(), 2, ',', '.');
                $imgPath  = $product->getImage();
                $imgHtml  = '';
                if ($imgPath && $imgPath !== 'no_selection') {
                    $imgUrl  = $mediaUrl . '/catalog/product' . $imgPath;
                    $imgHtml = '<img src="' . htmlspecialchars($imgUrl) . '" alt="'
                             . htmlspecialchars($product->getName()) . '" width="120"'
                             . ' style="max-width:120px;height:auto;float:left;margin-right:10px;margin-bottom:4px;">';
                }
                $cards .= sprintf(
                    '<div style="overflow:hidden;margin:8px 0;padding:8px;border:1px solid #eee;">'
                    . '%s<strong>%s</strong><br>SKU: %s<br>Preis: %s EUR'
                    . '<div style="clear:both;"></div></div>',
                    $imgHtml,
                    htmlspecialchars($product->getName()),
                    htmlspecialchars($product->getSku()),
                    $price
                );
                $plain[] = sprintf('  • %s (SKU: %s) – %s EUR', $product->getName(), $product->getSku(), $price);
            }
            $total = $results->getTotalCount();
            $html  = "<p><strong>Produkte ({$total} gefunden, Seite {$page}):</strong></p>" . $cards;

            return ['success' => true, 'response_text' => implode("\n", $plain), 'response_html' => $html, 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('[ToolExecutor] searchProductsByFilter failed: ' . $e->getMessage());
            // Surface a clean message; avoid exposing raw Magento internals (e.g. "attribute name is invalid")
            $msg = str_contains($e->getMessage(), 'attribute name is invalid')
                ? 'Das angegebene Attribut ist in Magento nicht als Filterattribut verfügbar. Bitte nutze die RAG-Suchergebnisse oder wähle ein anderes Attribut.'
                : 'Produktsuche fehlgeschlagen: ' . $e->getMessage();
            return $this->err($msg);
        }
    }

    // ─── Newsletter ──────────────────────────────────────────────────────────

    private function toggleNewsletter(array $params, array $customerData): array
    {
        $customerId = (int)($customerData['id'] ?? 0);
        $subscribe  = (bool)($params['subscribe'] ?? true);

        try {
            $subscriber = $this->subscriberFactory->create();
            if ($subscribe) {
                $subscriber->subscribeCustomerById($customerId);
                $msg = 'Sie wurden erfolgreich für den Newsletter angemeldet.';
            } else {
                $subscriber->unsubscribeCustomerById($customerId);
                $msg = 'Sie wurden erfolgreich vom Newsletter abgemeldet.';
            }
            return ['success' => true, 'response_text' => $msg,
                    'response_html' => '<p>' . $msg . '</p>', 'error' => null];
        } catch (\Throwable $e) {
            return $this->err('Newsletter-Status konnte nicht geändert werden: ' . $e->getMessage());
        }
    }

    // ─── Wishlist ────────────────────────────────────────────────────────────

    private function getWishlist(array $customerData, int $storeId): array
    {
        $customerId = (int)($customerData['id'] ?? 0);
        try {
            $wishlist = $this->wishlistFactory->create()->loadByCustomerId($customerId, true);
            $items    = [];
            foreach ($wishlist->getItemCollection() as $item) {
                $product = $item->getProduct();
                $items[] = sprintf(
                    '• %s (SKU: %s) – %.2f EUR',
                    $product->getName(), $product->getSku(), (float)$product->getFinalPrice()
                );
            }
            if (empty($items)) {
                $msg = 'Ihre Wunschliste ist leer.';
                return ['success' => true, 'response_text' => $msg,
                        'response_html' => '<p>' . $msg . '</p>', 'error' => null];
            }
            $msg  = 'Ihre Wunschliste (' . count($items) . " Artikel):\n" . implode("\n", $items);
            $html = '<p>Ihre Wunschliste (' . count($items) . ' Artikel):</p><ul>'
                  . implode('', array_map(fn($i) => '<li>' . htmlspecialchars($i) . '</li>', $items))
                  . '</ul>';
            return ['success' => true, 'response_text' => $msg, 'response_html' => $html, 'error' => null];
        } catch (\Throwable $e) {
            return $this->err('Wunschliste konnte nicht geladen werden: ' . $e->getMessage());
        }
    }

    private function wishlistAddItem(array $params, array $customerData, int $storeId): array
    {
        $sku        = trim((string)($params['sku'] ?? ''));
        $customerId = (int)($customerData['id'] ?? 0);
        if ($sku === '') {
            return $this->err('Bitte geben Sie eine SKU an.');
        }
        try {
            $product  = $this->productRepository->get($sku, false, $storeId);
            $wishlist = $this->wishlistFactory->create()->loadByCustomerId($customerId, true);
            $wishlist->addNewItem($product);
            $wishlist->save();
            $msg = sprintf('"%s" wurde zu Ihrer Wunschliste hinzugefügt.', $product->getName());
            return ['success' => true, 'response_text' => $msg,
                    'response_html' => '<p>' . htmlspecialchars($msg) . '</p>', 'error' => null];
        } catch (\Throwable $e) {
            return $this->err('Produkt konnte nicht zur Wunschliste hinzugefügt werden: ' . $e->getMessage());
        }
    }

    private function wishlistRemoveItem(array $params, array $customerData): array
    {
        $sku        = trim((string)($params['sku'] ?? ''));
        $customerId = (int)($customerData['id'] ?? 0);
        try {
            $wishlist = $this->wishlistFactory->create()->loadByCustomerId($customerId, true);
            foreach ($wishlist->getItemCollection() as $item) {
                if (strcasecmp((string)$item->getProduct()->getSku(), $sku) === 0) {
                    $name = $item->getProduct()->getName();
                    $item->delete();
                    $msg = sprintf('Artikel "%s" wurde von Ihrer Wunschliste entfernt.', $name);
                    return ['success' => true, 'response_text' => $msg,
                            'response_html' => '<p>' . htmlspecialchars($msg) . '</p>', 'error' => null];
                }
            }
            return $this->err(sprintf('SKU "%s" wurde auf Ihrer Wunschliste nicht gefunden.', $sku));
        } catch (\Throwable $e) {
            return $this->err('Wunschlisten-Eintrag konnte nicht entfernt werden: ' . $e->getMessage());
        }
    }

    private function wishlistMoveToCart(array $params, array $customerData, int $storeId): array
    {
        $sku        = trim((string)($params['sku'] ?? ''));
        $customerId = (int)($customerData['id'] ?? 0);
        try {
            $wishlist = $this->wishlistFactory->create()->loadByCustomerId($customerId, true);
            foreach ($wishlist->getItemCollection() as $item) {
                if (strcasecmp((string)$item->getProduct()->getSku(), $sku) === 0) {
                    $qty        = max(1, (int)($item->getQty() ?: 1));
                    $name       = $item->getProduct()->getName();
                    $cartResult = $this->cartAdd(
                        ['items' => [['sku' => $sku, 'qty' => $qty, 'name' => $name]]],
                        $customerData,
                        $storeId
                    );
                    if ($cartResult['success']) {
                        $item->delete();
                    }
                    return $cartResult;
                }
            }
            return $this->err(sprintf('SKU "%s" wurde auf Ihrer Wunschliste nicht gefunden.', $sku));
        } catch (\Throwable $e) {
            return $this->err('Artikel konnte nicht in den Warenkorb verschoben werden: ' . $e->getMessage());
        }
    }

    // ─── Stock notification ──────────────────────────────────────────────────

    private function setStockNotification(array $params, array $customerData, int $storeId): array
    {
        $sku        = trim((string)($params['sku'] ?? ''));
        $enable     = (bool)($params['enable'] ?? true);
        $customerId = (int)($customerData['id'] ?? 0);

        if ($sku === '') {
            return $this->err('Bitte geben Sie eine SKU an.');
        }
        try {
            $product   = $this->productRepository->get($sku, false, $storeId);
            $productId = (int)$product->getId();
            $websiteId = (int)$this->storeManager->getStore($storeId ?: null)->getWebsiteId();
            $resolvedStoreId = $storeId ?: (int)$this->storeManager->getDefaultStoreView()->getId();

            if ($enable) {
                $alert = $this->productAlertStockFactory->create();
                $alert->setCustomerId($customerId)
                      ->setProductId($productId)
                      ->setWebsiteId($websiteId)
                      ->setStoreId($resolvedStoreId)
                      ->setStatus(1)
                      ->setAddDate(date('Y-m-d H:i:s'));
                $alert->save();
                $msg = sprintf('Sie werden benachrichtigt, sobald "%s" wieder verfügbar ist.', $product->getName());
            } else {
                $collection = $this->productAlertStockFactory->create()->getCollection()
                    ->addFieldToFilter('customer_id', $customerId)
                    ->addFieldToFilter('product_id', $productId);
                foreach ($collection as $existing) {
                    $existing->delete();
                }
                $msg = sprintf('Lagerbenachrichtigung für "%s" wurde deaktiviert.', $product->getName());
            }
            return ['success' => true, 'response_text' => $msg,
                    'response_html' => '<p>' . htmlspecialchars($msg) . '</p>', 'error' => null];
        } catch (\Throwable $e) {
            return $this->err('Lagerbenachrichtigung konnte nicht gesetzt werden: ' . $e->getMessage());
        }
    }

    // ─── Redirect ────────────────────────────────────────────────────────────

    private function redirectToStore(array $params, int $storeId): array
    {
        $page  = (string)($params['page'] ?? 'account');
        $baseUrl = rtrim($this->storeManager->getStore($storeId ?: null)->getBaseUrl(), '/');
        $orderId = $params['order_id'] ?? null;

        $urlMap = [
            'account_edit'    => '/customer/account/edit/',
            'vault_cards'     => '/vault/cards/listaction/',
            'account_orders'  => '/sales/order/history/',
            'account_address' => '/customer/address/',
            'order_detail'    => $orderId ? '/sales/order/view/order_id/' . $orderId . '/' : '/sales/order/history/',
            'wishlist'        => '/wishlist/',
            'newsletter'      => '/newsletter/manage/',
            'account'         => '/customer/account/',
        ];

        $path = $urlMap[$page] ?? '/customer/account/';
        $url  = $baseUrl . $path;

        $labels = [
            'account_edit'    => 'Konto bearbeiten',
            'vault_cards'     => 'Zahlungsmethoden verwalten',
            'account_orders'  => 'Bestellhistorie',
            'account_address' => 'Adressen verwalten',
            'order_detail'    => 'Bestelldetails',
            'wishlist'        => 'Wunschliste',
            'newsletter'      => 'Newsletter-Einstellungen',
            'account'         => 'Mein Konto',
        ];
        $label = $labels[$page] ?? 'Shop';

        $msg  = "Diese Aktion ist über den Chat nicht verfügbar. Bitte öffnen Sie die entsprechende Seite in Ihrem Shop-Konto: {$url}";
        $html = "<p>Diese Aktion ist über den Chat nicht verfügbar. Bitte öffnen Sie: "
              . "<a href=\"{$url}\">{$label}</a></p>";

        return ['success' => true, 'response_text' => $msg, 'response_html' => $html, 'error' => null];
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function findOrderForCustomer(string $incrementId, string $customerEmail): ?array
    {
        if ($incrementId === '') {
            return null;
        }
        // Check the last 100 orders for this customer
        $orders = $this->orderHistory->getByCustomerEmail($customerEmail, 100);
        foreach ($orders as $o) {
            if ($o['increment_id'] === $incrementId) {
                return $o;
            }
        }
        return null;
    }

    private function getActiveCartId(int $customerId, int $storeId): ?int
    {
        $builder = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $customerId)
            ->addFilter('is_active', 1);
        if ($storeId > 0) {
            $builder->addFilter('store_id', $storeId);
        }
        $list  = $this->cartRepository->getList($builder->setPageSize(1)->create());
        $items = $list->getItems();
        if (empty($items)) {
            return null;
        }
        $cart = reset($items);
        return (int)$cart->getId();
    }

    private function resolveCategoryId(string $categoryName, int $storeId): ?int
    {
        try {
            // Use a FRESH builder — this runs while searchProductsByFilter() is still
            // assembling the shared $searchCriteriaBuilder with product filters (status,
            // visibility). Reusing that instance here would (a) drag those product-only
            // filters into the category query ("The 'status' attribute name is invalid")
            // and (b) reset the shared builder on create(), wiping the caller's filters.
            $criteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('name', $categoryName, 'like')
                ->addFilter('is_active', 1)
                ->setPageSize(1)
                ->create();
            $categories = $this->categoryList->getList($criteria)->getItems();
            if (!empty($categories)) {
                return (int)reset($categories)->getId();
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[ToolExecutor] resolveCategoryId failed: ' . $e->getMessage());
        }
        return null;
    }

    private function resolveInlineAddressRegion(array $address): array
    {
        if (!empty($address['region'])) {
            return $address;
        }
        $postcode  = $address['postcode'] ?? '';
        $countryId = $address['country_id'] ?? 'DE';
        $regionId  = $this->resolveRegionIdByPostcode($postcode, $countryId);
        if ($regionId > 0) {
            try {
                $region = $this->regionFactory->create()->load($regionId);
                $address['region']    = $region->getName();
                $address['region_id'] = $regionId;
            } catch (\Throwable) {}
        }
        return $address;
    }

    private function resolveRegionIdByPostcode(string $postcode, string $countryId): int
    {
        if ($postcode === '' || $countryId !== 'DE') {
            return 0;
        }
        // German postcode → Bundesland mapping (first 2 digits)
        $prefix = (int)substr($postcode, 0, 2);
        $regionCodeMap = [
            // BW: 68-79
            68 => 'BW', 69 => 'BW', 70 => 'BW', 71 => 'BW', 72 => 'BW',
            73 => 'BW', 74 => 'BW', 75 => 'BW', 76 => 'BW', 77 => 'BW',
            78 => 'BW', 79 => 'BW',
            // BY: 80-87, 90-97
            80 => 'BY', 81 => 'BY', 82 => 'BY', 83 => 'BY', 84 => 'BY',
            85 => 'BY', 86 => 'BY', 87 => 'BY', 90 => 'BY', 91 => 'BY',
            92 => 'BY', 93 => 'BY', 94 => 'BY', 95 => 'BY', 96 => 'BY', 97 => 'BY',
            // BE: 10-14
            10 => 'BE', 11 => 'BE', 12 => 'BE', 13 => 'BE', 14 => 'BE',
            // BB: 01, 03-04, 15-16
            15 => 'BB', 16 => 'BB',
            // HB: 27-28
            27 => 'HB', 28 => 'HB',
            // HH: 20-22
            20 => 'HH', 21 => 'HH', 22 => 'HH',
            // HE: 34-36, 59-60, 63-65
            34 => 'HE', 35 => 'HE', 36 => 'HE', 60 => 'HE', 61 => 'HE',
            63 => 'HE', 64 => 'HE', 65 => 'HE',
            // MV: 17-19, 23
            17 => 'MV', 18 => 'MV', 19 => 'MV', 23 => 'MV',
            // NI: 26-32, 37-38, 48-49
            26 => 'NI', 29 => 'NI', 30 => 'NI', 31 => 'NI', 32 => 'NI',
            37 => 'NI', 38 => 'NI', 48 => 'NI', 49 => 'NI',
            // NW: 33, 40-47, 50-58
            33 => 'NW', 40 => 'NW', 41 => 'NW', 42 => 'NW', 44 => 'NW',
            45 => 'NW', 46 => 'NW', 47 => 'NW', 50 => 'NW', 51 => 'NW',
            52 => 'NW', 53 => 'NW', 54 => 'NW', 55 => 'NW', 56 => 'NW',
            57 => 'NW', 58 => 'NW',
            // RP: 54-57, 66-67
            66 => 'RP', 67 => 'RP',
            // SL: 66
            // SN: 01-09
            1  => 'SN', 2 => 'SN', 3 => 'SN', 4 => 'SN', 5 => 'SN',
            6  => 'ST', 7 => 'SN', 8 => 'SN', 9 => 'SN',
            // ST: 06, 39
            39 => 'ST',
            // SH: 22-25
            24 => 'SH', 25 => 'SH',
            // TH: 98-99
            98 => 'TH', 99 => 'TH',
        ];

        $regionCode = $regionCodeMap[$prefix] ?? null;
        if ($regionCode === null) {
            return 0;
        }

        try {
            $region = $this->regionFactory->create();
            $region->loadByCode($regionCode, 'DE');
            return $region->getId() ? (int)$region->getId() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function buildCartHtml(array $cart): string
    {
        if (empty($cart['items'])) {
            return '<p><em>Warenkorb ist jetzt leer.</em></p>';
        }
        $rows = '';
        foreach ($cart['items'] as $item) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%d</td><td>%.2f EUR</td></tr>',
                htmlspecialchars($item['name']),
                htmlspecialchars($item['sku']),
                (int)$item['qty'],
                (float)$item['row_total']
            );
        }
        return sprintf(
            '<table><thead><tr><th>Produkt</th><th>SKU</th><th>Menge</th><th>Gesamt</th></tr></thead>'
            . '<tbody>%s</tbody></table><p><strong>Zwischensumme: %.2f EUR</strong></p>'
            . '<p>Möchten Sie die Bestellung aufgeben?</p>',
            $rows, (float)($cart['subtotal'] ?? 0)
        );
    }

    /**
     * @return array{success: false, response_text: string, response_html: string, error: string}
     */
    /**
     * Resolve a human-readable dropdown option label to its Magento option ID.
     * Returns null when the attribute is not a select/dropdown or no matching option is found
     * (caller should fall back to LIKE filter).
     */
    private function resolveDropdownOptionId(string $attrCode, string $label): ?string
    {
        try {
            $attribute = $this->eavConfig->getAttribute('catalog_product', $attrCode);
            if (!$attribute || !$attribute->getId()) {
                return null;
            }
            $frontendInput = $attribute->getFrontendInput();
            if (!in_array($frontendInput, ['select', 'multiselect'], true)) {
                return null; // text/textarea/etc. — LIKE is correct
            }
            $labelLower = mb_strtolower($label);
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                if (mb_strtolower((string)($option['label'] ?? '')) === $labelLower) {
                    return (string)$option['value'];
                }
            }
            // No exact match — try contains
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                if (str_contains(mb_strtolower((string)($option['label'] ?? '')), $labelLower)) {
                    return (string)$option['value'];
                }
            }
            $this->logger->warning('[ToolExecutor] resolveDropdownOptionId: no option match', [
                'attribute' => $attrCode,
                'label'     => $label,
            ]);
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('[ToolExecutor] resolveDropdownOptionId failed: ' . $e->getMessage());
            return null;
        }
    }

    private function err(string $message): array
    {
        return [
            'success'       => false,
            'response_text' => $message,
            'response_html' => '<p>' . htmlspecialchars($message) . '</p>',
            'error'         => $message,
        ];
    }

    private function unknownTool(string $toolName): array
    {
        $msg = "Unbekannte Aktion '{$toolName}'.";
        return ['success' => false, 'response_text' => $msg, 'response_html' => '<p>' . $msg . '</p>', 'error' => $msg];
    }
}
