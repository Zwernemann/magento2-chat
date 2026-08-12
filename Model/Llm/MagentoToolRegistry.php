<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Defines the catalog of available Magento tools the LLM can call.
 *
 * Tools disabled in the admin are excluded from the catalog — the LLM
 * cannot select tools it never sees. redirect_to_store is always included.
 */
class MagentoToolRegistry
{
    private const TOOLS = [
        // ─── Orders ────────────────────────────────────────────────────────────
        'cart_add_item' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/cart_add_item',
            'description' => 'Einen oder mehrere Artikel zum Warenkorb hinzufügen. Zeige danach immer den Warenkorb-Inhalt und frage ob bestellt werden soll.',
            'params'      => [
                'items' => [
                    'type'        => 'array',
                    'description' => 'Artikel-Liste',
                    'items'       => [
                        'type'       => 'object',
                        'properties' => [
                            'sku'     => ['type' => 'string'],
                            'qty'     => ['type' => 'integer'],
                            'name'    => ['type' => 'string'],
                            'options' => ['type' => 'object', 'description' => 'Konfigurations-Optionen z.B. {"color":"Rot"}'],
                        ],
                        'required' => ['sku', 'qty', 'name'],
                    ],
                ],
            ],
        ],
        'cart_update_item' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/cart_update_item',
            'description' => 'Menge eines Warenkorb-Artikels ändern (Zielmenge, kein Delta). qty=0 entfernt den Artikel.',
            'params'      => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'sku' => ['type' => 'string'],
                            'qty' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ],
        ],
        'cart_remove_item' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/cart_remove_item',
            'description' => 'Artikel aus dem Warenkorb entfernen.',
            'params'      => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => ['sku' => ['type' => 'string']],
                    ],
                ],
            ],
        ],
        'cart_checkout' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/cart_checkout',
            'description' => 'Den aktiven Warenkorb als Bestellung aufgeben. Direkt nach cart_add_item aufrufen wenn der Kunde explizit "bestelle" / "ich möchte bestellen" sagt — KEINE separate Bestätigungsfrage nötig. NUR nachfragen wenn der Warenkorb bereits Artikel enthielt die NICHT Teil dieser Anfrage sind (dann alle Positionen auflisten und einmalig bestätigen lassen).',
            'params'      => [
                'po_number'           => ['type' => 'string', 'description' => 'Purchase Order Nummer (falls erforderlich)'],
                'payment_method'      => ['type' => 'string', 'description' => 'Zahlart-Code: checkmo, purchaseorder. Leer = Admin-Standard.'],
                'vault_payment_token' => ['type' => 'string', 'description' => 'Token-ID einer gespeicherten Kreditkarte (z.B. vault_0012)'],
                'shipping_address_id' => ['type' => 'integer', 'description' => 'ID einer hinterlegten Lieferadresse (aus LIEFERADRESSEN-Kontext)'],
                'shipping_address'    => [
                    'type'        => 'object',
                    'description' => 'Inline-Lieferadresse wenn neu (nicht im Konto hinterlegt)',
                    'properties'  => [
                        'firstname'  => ['type' => 'string'],
                        'lastname'   => ['type' => 'string'],
                        'street'     => ['type' => 'string'],
                        'postcode'   => ['type' => 'string'],
                        'city'       => ['type' => 'string'],
                        'country_id' => ['type' => 'string', 'description' => 'ISO 2-Letter (DE, AT, CH)'],
                        'telephone'  => ['type' => 'string'],
                    ],
                ],
            ],
        ],
        'reorder_from_history' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/reorder_from_history',
            'description' => 'Bestellung aus der Bestellhistorie in den Warenkorb legen (zum erneuten Bestellen).',
            'params'      => [
                'order_increment_id' => ['type' => 'string', 'description' => 'Bestellnummer (z.B. 100023456)'],
            ],
        ],
        'get_order_history' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/get_order_history',
            'description' => 'Erweiterte Bestellhistorie abfragen. Nutzen wenn nach Bestellungen außerhalb des Standard-Kontexts gesucht wird (z.B. "vor 3 Jahren", nach Produkt, nach Status).',
            'params'      => [
                'date_from' => ['type' => 'string', 'description' => 'Von-Datum ISO (YYYY-MM-DD)'],
                'date_to'   => ['type' => 'string', 'description' => 'Bis-Datum ISO (YYYY-MM-DD)'],
                'status'    => ['type' => 'string', 'description' => 'Bestellstatus: complete, pending, processing, canceled'],
                'sku'       => ['type' => 'string', 'description' => 'Suche nach SKU in Bestellpositionen'],
                'limit'     => ['type' => 'integer', 'description' => 'Max. Anzahl Ergebnisse (Standard 20, max. 100)'],
                'page'      => ['type' => 'integer', 'description' => 'Seite für Pagination (ab 1)'],
            ],
        ],
        'get_order_detail' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/get_order_detail',
            'description' => 'Detaillierte Informationen zu einer einzelnen Bestellung inkl. Positionen, Versandstatus und Tracking.',
            'params'      => [
                'order_increment_id' => ['type' => 'string', 'description' => 'Bestellnummer'],
            ],
        ],
        'get_shipment_tracking' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/get_shipment_tracking',
            'description' => 'Tracking-Nummern und Versandinformationen für eine Bestellung.',
            'params'      => [
                'order_increment_id' => ['type' => 'string', 'description' => 'Bestellnummer'],
            ],
        ],
        'get_invoice' => [
            'group'       => 'orders',
            'config_path' => 'zwernemann_chat/tools/get_invoice',
            'description' => 'Rechnungsinformationen zu einer Bestellung abrufen.',
            'params'      => [
                'order_increment_id' => ['type' => 'string', 'description' => 'Bestellnummer'],
            ],
        ],
        // ─── Addresses ──────────────────────────────────────────────────────────
        'get_shipping_addresses' => [
            'group'       => 'addresses',
            'config_path' => 'zwernemann_chat/tools/get_shipping_addresses',
            'description' => 'Alle hinterlegten Lieferadressen des Kunden mit IDs anzeigen.',
            'params'      => [],
        ],
        'add_shipping_address' => [
            'group'       => 'addresses',
            'config_path' => 'zwernemann_chat/tools/add_shipping_address',
            'description' => 'Neue Lieferadresse zum Kundenkonto hinzufügen.',
            'params'      => [
                'firstname'      => ['type' => 'string'],
                'lastname'       => ['type' => 'string'],
                'street'         => ['type' => 'string', 'description' => 'Straße und Hausnummer'],
                'postcode'       => ['type' => 'string'],
                'city'           => ['type' => 'string'],
                'country_id'     => ['type' => 'string', 'description' => 'ISO 2-Letter (DE, AT, CH)'],
                'telephone'      => ['type' => 'string'],
                'set_as_default' => ['type' => 'boolean', 'description' => 'Als Standard-Lieferadresse setzen'],
            ],
        ],
        'set_order_shipping_address' => [
            'group'       => 'addresses',
            'config_path' => 'zwernemann_chat/tools/set_order_shipping_address',
            'description' => 'Lieferadresse einer noch nicht versendeten Bestellung ändern.',
            'params'      => [
                'order_increment_id'  => ['type' => 'string'],
                'shipping_address_id' => ['type' => 'integer', 'description' => 'ID einer hinterlegten Adresse'],
            ],
        ],
        // ─── Coupons ────────────────────────────────────────────────────────────
        'apply_coupon_code' => [
            'group'       => 'coupons',
            'config_path' => 'zwernemann_chat/tools/apply_coupon_code',
            'description' => 'Gutscheincode auf den aktiven Warenkorb anwenden.',
            'params'      => [
                'coupon_code' => ['type' => 'string'],
            ],
        ],
        'remove_coupon_code' => [
            'group'       => 'coupons',
            'config_path' => 'zwernemann_chat/tools/remove_coupon_code',
            'description' => 'Gutscheincode vom Warenkorb entfernen.',
            'params'      => [],
        ],
        // ─── Wishlist ────────────────────────────────────────────────────────────
        'get_wishlist' => [
            'group'       => 'wishlist',
            'config_path' => 'zwernemann_chat/tools/get_wishlist',
            'description' => 'Wunschliste des Kunden anzeigen.',
            'params'      => [],
        ],
        'wishlist_add_item' => [
            'group'       => 'wishlist',
            'config_path' => 'zwernemann_chat/tools/wishlist_add_item',
            'description' => 'Produkt zur Wunschliste hinzufügen.',
            'params'      => ['sku' => ['type' => 'string']],
        ],
        'wishlist_remove_item' => [
            'group'       => 'wishlist',
            'config_path' => 'zwernemann_chat/tools/wishlist_remove_item',
            'description' => 'Produkt von der Wunschliste entfernen.',
            'params'      => ['sku' => ['type' => 'string']],
        ],
        'wishlist_move_to_cart' => [
            'group'       => 'wishlist',
            'config_path' => 'zwernemann_chat/tools/wishlist_move_to_cart',
            'description' => 'Wunschlisten-Artikel in den Warenkorb verschieben.',
            'params'      => ['sku' => ['type' => 'string']],
        ],
        // ─── Account ─────────────────────────────────────────────────────────────
        'get_account_info' => [
            'group'       => 'account',
            'config_path' => 'zwernemann_chat/tools/get_account_info',
            'description' => 'Kontodaten des Kunden anzeigen (Name, E-Mail, Firma, Kundengruppe).',
            'params'      => [],
        ],
        'update_account_info' => [
            'group'       => 'account',
            'config_path' => 'zwernemann_chat/tools/update_account_info',
            'description' => 'Kontoname oder Firma aktualisieren (kein Passwort, keine E-Mail-Adresse über diesen Kanal).',
            'params'      => [
                'firstname' => ['type' => 'string'],
                'lastname'  => ['type' => 'string'],
                'company'   => ['type' => 'string'],
            ],
        ],
        'toggle_newsletter' => [
            'group'       => 'account',
            'config_path' => 'zwernemann_chat/tools/toggle_newsletter',
            'description' => 'Newsletter-Abonnement des Kunden aktivieren oder deaktivieren.',
            'params'      => ['subscribe' => ['type' => 'boolean']],
        ],
        // ─── Products ────────────────────────────────────────────────────────────
        'search_products_by_filter' => [
            'group'       => 'products',
            'config_path' => 'zwernemann_chat/tools/search_products_by_filter',
            'description' => 'Produkte nach Kategorie, Attribut oder Lagerstatus strukturiert suchen. '
                . 'Für Custom-Attribute aus Produkt-Metadaten (erkennbar am Präfix attr_ in den Suchergebnissen, '
                . 'z.B. attr_bb_supplier_auto_order): attribute_code = Schlüssel MIT attr_-Präfix übergeben, '
                . 'der Executor strippt es automatisch. Boolean-Werte: Nein/no → 0, Ja/yes → 1. '
                . 'Text-Attribute werden als LIKE-Filter ausgeführt (Teilstring genügt).',
            'params'      => [
                'category_name'  => ['type' => 'string', 'description' => 'Kategoriename (z.B. Tassen, Bürobedarf)'],
                'in_stock'       => ['type' => 'boolean'],
                'attribute_code' => ['type' => 'string', 'description' => 'Attribut-Key aus den Metadaten inkl. attr_-Präfix (z.B. attr_bb_supplier_auto_order) ODER direkter Magento-Code (z.B. color)'],
                'attribute_value'=> ['type' => 'string', 'description' => 'Gesuchter Wert. Boolean: Nein/no → 0, Ja/yes → 1. Text: Teilstring genügt.'],
                'sort_by'        => ['type' => 'string', 'description' => 'Attributcode für Sortierung (z.B. name, price)'],
                'sort_direction' => ['type' => 'string', 'description' => 'ASC oder DESC'],
                'page_size'      => ['type' => 'integer', 'description' => 'Ergebnisse pro Seite (max. 50, Standard 20)'],
                'page'           => ['type' => 'integer'],
            ],
        ],
        'set_stock_notification' => [
            'group'       => 'products',
            'config_path' => 'zwernemann_chat/tools/set_stock_notification',
            'description' => 'Lagerbenachrichtigung für ein nicht verfügbares Produkt aktivieren.',
            'params'      => [
                'sku'    => ['type' => 'string'],
                'enable' => ['type' => 'boolean'],
            ],
        ],
        // ─── Fallback (immer aktiv) ───────────────────────────────────────────────
        'redirect_to_store' => [
            'group'       => 'fallback',
            'config_path' => null,
            'description' => 'Weiterleitung zu einer Shop-Seite. Verwenden wenn eine Anfrage aus Sicherheits- oder Scope-Gründen nicht über den Chat bearbeitet werden kann (Passwort ändern, neue Kreditkarte hinterlegen etc.).',
            'params'      => [
                'page'     => ['type' => 'string', 'description' => 'Seiten-Schlüssel: account_edit, vault_cards, account_orders, account_address, order_detail, wishlist, newsletter'],
                'order_id' => ['type' => 'string', 'description' => 'Bestellnummer für order_detail Seite (optional)'],
            ],
        ],
    ];

    public function __construct(
        private readonly ScopeConfigInterface $config
    ) {}

    /**
     * Returns enabled tool definitions only.
     * redirect_to_store is always included regardless of config.
     *
     * @return array<string, array{description: string, params: array}>
     */
    public function getEnabledTools(int $storeId = 0): array
    {
        $scope  = $storeId > 0 ? ScopeInterface::SCOPE_STORE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $result = [];

        foreach (self::TOOLS as $name => $def) {
            $configPath = $def['config_path'];
            if ($configPath === null || $this->config->isSetFlag($configPath, $scope, $storeId ?: null)) {
                $result[$name] = [
                    'description' => $def['description'],
                    'params'      => $def['params'],
                ];
            }
        }

        return $result;
    }

    /**
     * Builds a human-readable tool catalog block for the LLM context.
     */
    /**
     * @param string[] $allowList  If non-empty, only include these tool names (for slim non-product contexts).
     */
    public function buildToolCatalog(int $storeId = 0, array $allowList = []): string
    {
        $tools = $this->getEnabledTools($storeId);
        if (!empty($allowList)) {
            $tools = array_intersect_key($tools, array_flip($allowList));
        }
        if (empty($tools)) {
            return '';
        }

        $lines = ['=== VERFÜGBARE MAGENTO-AKTIONEN (tool_calls) ==='];
        foreach ($tools as $name => $def) {
            $lines[] = '';
            $lines[] = '**' . $name . '**: ' . $def['description'];
            if (!empty($def['params'])) {
                foreach ($def['params'] as $param => $schema) {
                    $type = $schema['type'] ?? 'string';
                    $desc = isset($schema['description']) ? ': ' . $schema['description'] : '';
                    $lines[] = '  - ' . $param . ' (' . $type . ')' . $desc;
                }
            }
        }

        return implode("\n", $lines);
    }

    /** @return string[] */
    public function getAllToolNames(): array
    {
        return array_keys(self::TOOLS);
    }
}
