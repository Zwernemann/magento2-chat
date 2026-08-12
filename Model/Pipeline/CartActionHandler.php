<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Pipeline;

use Zwernemann\Chat\Api\Shop\CartServiceInterface;
use Zwernemann\Chat\Api\Shop\ProductLookupInterface;
use Psr\Log\LoggerInterface;

/**
 * Handles all cart and order actions triggered by LLM intent.
 *
 * Extracted from MessageProcessor to keep orchestration logic separate from
 * shop-operation logic. Depends only on shop-system interfaces, not on
 * concrete Magento classes, making it portable across platforms.
 */
class CartActionHandler
{
    public function __construct(
        private readonly CartServiceInterface    $cartService,
        private readonly ProductLookupInterface  $productLookup,
        private readonly LoggerInterface         $logger
    ) {}

    /**
     * Validate SKUs, place order via CartService, update $llmResult with confirmation/error text.
     *
     * @param array<string, mixed>  $action
     * @param array<string, mixed>  $customerData
     * @param array<string, mixed> &$llmResult    Modified in place
     */
    public function handleOrderCreation(
        array $action,
        array $customerData,
        array &$llmResult,
        string $responseText,
        int $storeId = 0
    ): string {
        $items = $action['order_items'] ?? [];
        if (empty($items)) {
            return $responseText;
        }

        $skus          = array_column($items, 'sku');
        $validProducts = $this->productLookup->getMultipleBySkus($skus);
        $validItems    = [];
        $missingSkus   = [];

        foreach ($items as $item) {
            if (isset($validProducts[$item['sku']])) {
                $validItems[] = $item;
            } else {
                $missingSkus[] = $item['sku'];
            }
        }

        if (!empty($missingSkus)) {
            $this->logger->warning(
                'Zwernemann_Chat: Invalid SKUs in order: ' . implode(', ', $missingSkus)
            );
        }

        if (empty($validItems)) {
            $llmResult['response_text'] = 'Die angegebenen Produkte konnten nicht im Katalog gefunden werden. '
                . 'Bitte prüfen Sie Ihre Anfrage.';
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
            return $llmResult['response_text'];
        }

        $poNumber    = (string)($action['po_number'] ?? '');
        $orderResult = $this->cartService->createOrder(
            (int)$customerData['id'],
            $validItems,
            $customerData,
            $poNumber,
            $storeId
        );

        if (!$orderResult['success'] && ($orderResult['error'] ?? '') === 'needs_po_number') {
            $askText = 'Für Ihre Bestellung benötigen wir eine Bestellnummer (Purchase Order Number / PO-Nummer). '
                     . 'Bitte antworten Sie mit Ihrer PO-Nummer, damit wir die Bestellung direkt anlegen können.';
            $llmResult['response_text']  = $askText;
            $llmResult['response_html']  = '<p>' . nl2br(htmlspecialchars($askText)) . '</p>';
            $llmResult['action']['type'] = 'ask_clarification';
            return $askText;
        }

        if ($orderResult['success']) {
            $orderRef = $orderResult['increment_id'] ?? (string)$orderResult['order_id'];
            $itemList = implode(', ', array_map(
                fn($i) => ($i['qty'] ?? 1) . 'x ' . ($i['name'] ?? $i['sku']),
                $validItems
            ));
            $fallbackText = sprintf(
                "Ihre Bestellung #%s wurde erfolgreich angelegt.\n\nBestellte Artikel:\n%s\n\n"
                . "Sie erhalten in Kürze eine Auftragsbestätigung per E-Mail.",
                $orderRef, $itemList
            );
            $fallbackHtml = sprintf(
                '<p>Ihre Bestellung <strong>#%s</strong> wurde erfolgreich angelegt.</p>'
                . '<p><strong>Bestellte Artikel:</strong><br>%s</p>'
                . '<p>Sie erhalten in Kürze eine Auftragsbestätigung per E-Mail.</p>',
                htmlspecialchars($orderRef),
                implode('<br>', array_map(
                    fn($i) => htmlspecialchars(($i['qty'] ?? 1) . 'x ' . ($i['name'] ?? $i['sku'])),
                    $validItems
                ))
            );
            $merged = OrderConfirmationFormatter::inject(
                (string)($llmResult['response_text'] ?? ''),
                (string)($llmResult['response_html'] ?? ''),
                (string)$orderRef,
                $fallbackText,
                $fallbackHtml
            );
            $llmResult['response_text'] = $merged['text'];
            $llmResult['response_html'] = $merged['html'];
        } else {
            $llmResult['response_text'] = 'Die Bestellung konnte leider nicht automatisch angelegt werden. '
                . 'Bitte kontaktieren Sie uns direkt. Fehler: ' . ($orderResult['error'] ?? 'Unbekannt');
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
        }

        return $llmResult['response_text'];
    }

    /** @param array<string, mixed> &$llmResult */
    public function handleCartAdd(array $action, array $customerData, array &$llmResult, int $storeId = 0): void
    {
        $items  = $action['order_items'] ?? [];
        $result = $this->cartService->addItemsToCart((int)$customerData['id'], $items, $customerData, $storeId);
        if ($result['success']) {
            $llmResult['response_text'] = 'Die Artikel wurden zu deinem Warenkorb hinzugefügt.';
            $llmResult['response_html'] = '<p>Die Artikel wurden zu deinem Warenkorb hinzugefügt.</p>'
                . $this->buildCartHtml($result['cart']);
        } else {
            $llmResult['response_text'] = 'Fehler beim Hinzufügen: ' . ($result['error'] ?? 'Unbekannt');
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
        }
    }

    /** @param array<string, mixed> &$llmResult */
    public function handleCartUpdate(array $action, array $customerData, array &$llmResult, int $storeId = 0): void
    {
        $customerId = (int)$customerData['id'];
        $errors     = [];
        $lastCart   = [];
        foreach ($action['order_items'] ?? [] as $item) {
            $result = $this->cartService->updateCartItem($customerId, (string)$item['sku'], (int)$item['qty'], $storeId);
            if (!$result['success']) {
                $errors[] = $item['sku'] . ': ' . $result['error'];
            } else {
                $lastCart = $result['cart'];
            }
        }
        if (empty($errors)) {
            $llmResult['response_text'] = 'Die Menge wurde im Warenkorb aktualisiert.';
            $llmResult['response_html'] = '<p>Die Menge wurde im Warenkorb aktualisiert.</p>'
                . $this->buildCartHtml($lastCart);
        } else {
            $llmResult['response_text'] = 'Fehler: ' . implode('; ', $errors);
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
        }
    }

    /** @param array<string, mixed> &$llmResult */
    public function handleCartRemove(array $action, array $customerData, array &$llmResult, int $storeId = 0): void
    {
        $customerId = (int)$customerData['id'];
        $errors     = [];
        $lastCart   = [];
        foreach ($action['order_items'] ?? [] as $item) {
            $result = $this->cartService->removeCartItem($customerId, (string)$item['sku'], $storeId);
            if (!$result['success']) {
                $errors[] = $item['sku'] . ': ' . $result['error'];
            } else {
                $lastCart = $result['cart'];
            }
        }
        if (empty($errors)) {
            $llmResult['response_text'] = 'Der Artikel wurde aus dem Warenkorb entfernt.';
            $llmResult['response_html'] = '<p>Der Artikel wurde aus dem Warenkorb entfernt.</p>'
                . $this->buildCartHtml($lastCart);
        } else {
            $llmResult['response_text'] = 'Fehler: ' . implode('; ', $errors);
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
        }
    }

    /** @param array<string, mixed> &$llmResult */
    public function handleCartCheckout(array $action, array $customerData, array &$llmResult, int $storeId = 0): void
    {
        $poNumber    = (string)($action['po_number'] ?? '');
        $orderResult = $this->cartService->checkoutCart(
            (int)$customerData['id'], $customerData, $poNumber, $storeId
        );

        if (!$orderResult['success'] && ($orderResult['error'] ?? '') === 'needs_po_number') {
            $askText = 'Für Ihre Bestellung benötigen wir eine Bestellnummer (Purchase Order Number / PO-Nummer). '
                     . 'Bitte antworten Sie mit Ihrer PO-Nummer, damit wir die Bestellung direkt anlegen können.';
            $llmResult['response_text']  = $askText;
            $llmResult['response_html']  = '<p>' . nl2br(htmlspecialchars($askText)) . '</p>';
            $llmResult['action']['type'] = 'ask_clarification';
            return;
        }

        if ($orderResult['success']) {
            $orderRef = $orderResult['increment_id'] ?? (string)$orderResult['order_id'];
            $llmResult['response_text'] = sprintf(
                "Dein Warenkorb wurde als Bestellung #%s erfolgreich aufgegeben.\n\n"
                . "Du erhältst in Kürze eine Auftragsbestätigung per E-Mail.",
                $orderRef
            );
            $llmResult['response_html'] = sprintf(
                '<p>Dein Warenkorb wurde als Bestellung <strong>#%s</strong> erfolgreich aufgegeben.</p>'
                . '<p>Du erhältst in Kürze eine Auftragsbestätigung per E-Mail.</p>',
                htmlspecialchars($orderRef)
            );
        } else {
            $llmResult['response_text'] = 'Die Bestellung konnte nicht aufgegeben werden: '
                . ($orderResult['error'] ?? 'Unbekannt');
            $llmResult['response_html'] = '<p>' . htmlspecialchars($llmResult['response_text']) . '</p>';
        }
    }

    public function buildCartHtml(array $cart): string
    {
        if (empty($cart['items'])) {
            return '<p><em>Dein Warenkorb ist jetzt leer.</em></p>';
        }
        $rows = '';
        foreach ($cart['items'] as $item) {
            $rows .= sprintf(
                '<tr><td>%s</td><td>%s</td><td style="text-align:right">%d</td><td style="text-align:right">%.2f EUR</td></tr>',
                htmlspecialchars($item['name']),
                htmlspecialchars($item['sku']),
                (int)$item['qty'],
                (float)$item['row_total']
            );
        }
        return sprintf(
            '<table><thead><tr><th>Produkt</th><th>SKU</th><th>Menge</th><th>Gesamt</th></tr></thead>'
            . '<tbody>%s</tbody></table>'
            . '<p><strong>Zwischensumme: %.2f EUR</strong></p>',
            $rows,
            (float)($cart['subtotal'] ?? 0)
        );
    }
}
