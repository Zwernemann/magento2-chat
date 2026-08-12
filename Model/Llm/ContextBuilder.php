<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;
use Zwernemann\Chat\Model\Attachment\ExtractedAttachment;
use Zwernemann\Chat\Model\Llm\MagentoToolRegistry;
use Zwernemann\Chat\Model\Pipeline\OrderConfirmationFormatter;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Assembles the full LLM context from all available data sources:
 * – customer info
 * – order history
 * – RAG product search results
 * – conversation history
 */
class ContextBuilder
{
    private const XML_PATH_ADDITIONS        = 'zwernemann_chat/llm/system_prompt_additions';
    private const XML_PATH_ADDRESS_FORM     = 'zwernemann_chat/llm/address_form';
    private const XML_PATH_SHOP_NAME        = 'zwernemann_chat/llm/shop_name';
    private const XML_PATH_FROM_NAME        = 'zwernemann_chat/smtp/from_name';
    private const XML_PATH_MAX_CHARS        = 'zwernemann_chat/llm/history_message_max_chars';
    private const XML_PATH_HISTORY_TURNS    = 'zwernemann_chat/llm/history_turns_main';
    private const XML_PATH_HISTORY_TURNS_ACCOUNT = 'zwernemann_chat/llm/history_turns_account';

    /**
     * Sentinel prepended by the LLM to its degraded notice.
     * Stripped from history turns so the note never pollutes future context.
     * Public so MessageProcessor can strip it from display strings.
     */
    public const DEGRADED_MARKER = '<!--cc-degraded-->';

    public function __construct(
        private readonly ScopeConfigInterface $config,
        private readonly PipelineLogger       $pipelineLogger,
        private readonly MagentoToolRegistry  $toolRegistry
    ) {}

    /**
     * Build the full system prompt sent to Claude.
     * = fixed base prompt (SystemPromptProvider, not editable)
     *   + runtime salutation/signature directive (from backend config, outside the prompt)
     *   + operator additions (from backend config)
     *   + always-fixed behavioural sections below.
     * The instructions part is cached by Anthropic on repeated calls.
     */
    public function buildSystemPrompt(): string
    {
        $instructions = SystemPromptProvider::BASE_PROMPT;

        // Salutation/signature is configured in the backend (NOT inside the prompt text)
        // and turned into a concrete directive here so the model always greets and signs
        // off consistently — regardless of which model (Sonnet/Opus) is selected.
        $instructions .= "\n\n" . $this->buildAddressDirective();

        // Operator additions: appended to the fixed base prompt (Punkt 3 — "Ergänzungen").
        $additions = trim((string)$this->config->getValue(self::XML_PATH_ADDITIONS));
        if ($additions !== '') {
            $instructions .= "\n\n=== ZUSÄTZLICHE ANWEISUNGEN DES SHOP-BETREIBERS ===\n" . $additions;
        }

        $instructions .= "\n\n"
            . 'Der Kunde kann Dateianhänge mitsenden (PDF, Excel, Word). Diese können strukturierte '
            . 'Bestelllisten (Spalten wie SKU/Artikelnummer, Menge) oder unstrukturierte Informationen '
            . 'enthalten. Extrahiere Bestellpositionen aus Anhängen und befülle die entsprechenden Tool-Parameter.';

        $instructions .= "\n\n"
            . 'ANHANG-POSITIONEN MERKEN: Wenn du Bestellpositionen aus einem Anhang oder der Nachricht '
            . 'extrahierst, befülle IMMER das Feld extracted_items mit allen Positionen ({sku, name, qty}) '
            . '— auch und gerade dann, wenn du erst eine Rückfrage stellst (intent=ask_clarification). '
            . 'Liste die extrahierten Positionen außerdem in response_text und response_html auf '
            . '(Tabelle mit Position, Produktname, SKU, Menge), damit der Kunde sie prüfen kann. '
            . 'Frage den Kunden NIEMALS nach internen Shop-SKUs — die Zuordnung über die '
            . 'RAG-Suchergebnisse ist deine Aufgabe. Wenn einzelne Positionen keinem '
            . 'Katalogartikel zugeordnet werden können: liste sie separat als "nicht im '
            . 'Katalog gefunden" und frage, ob die zuordenbaren Positionen bestellt werden sollen. '
            . 'Lege in diesem Fall noch nichts in den Warenkorb und schließe nicht ab — setze '
            . 'intent=ask_clarification, lasse tool_calls leer und warte auf die Bestätigung des Kunden.';

        $instructions .= "\n\n"
            . 'SKU-INTEGRITÄT (zwingend): Verwende SKUs IMMER exakt so, wie sie in den RAG-Suchergebnissen, '
            . 'den extrahierten Positionen oder dem Bestellverlauf stehen. Du darfst SKUs NIEMALS erfinden, '
            . 'abändern, kürzen, zusammensetzen oder mit Zusätzen versehen — insbesondere KEINE Farb-, Größen- '
            . 'oder Varianten-Suffixe an die SKU anhängen (bilde z.B. NICHT "ELK-0457-weiss" aus "ELK-0457"). '
            . 'Kennst du die exakte SKU einer Position nicht, behandle sie als "nicht im Katalog gefunden" und '
            . 'frage nach — rate niemals eine SKU.';

        $instructions .= "\n\n"
            . 'KONFIGURIERBARE ARTIKEL (Farbe/Größe etc.): Zeigt ein RAG-Treffer "Konfigurierbare Optionen: …", '
            . 'übergib in cart_add_item die UNVERÄNDERTE Eltern-SKU und lege die gewählte Variante in das Feld '
            . '"options" (z.B. {"Farbe": "Weiß"}). Kodiere die Variante NIE in den SKU-String. Fehlt eine '
            . 'erforderliche Option (z.B. Farbe), frage sie vor der Bestellung ab — nicht raten.';

        $instructions .= "\n\n"
            . 'TOOL-SYSTEM: Im Kontext steht ein Katalog verfügbarer Magento-Aktionen (=== VERFÜGBARE MAGENTO-AKTIONEN ===). '
            . 'Befülle tool_calls mit den gewünschten Aktionen. Für reine Informationsantworten und Rückfragen bleibt tool_calls leer. '
            . 'Angezeigte Preise sind Katalog- bzw. Gruppenpreise. Warenkorb-Aktionen und Promotionen werden von Magento beim Bestellabschluss automatisch verrechnet. '
            . 'Das tatsächliche Warenkorb-Gesamttotal nach Regelanwendung steht im Kontext unter AKTUELLER WARENKORB.';

        $instructions .= "\n\n"
            . 'BESTELLFLUSS: Wenn der Kunde explizit "bestelle", "bitte bestell", "ich möchte bestellen" o.ä. sagt, '
            . 'rufe cart_add_item UND cart_checkout in einem einzigen tool_calls-Array auf — ohne separate Bestätigungsfrage. '
            . 'PO-Nummern und Zahlarten aus derselben Nachricht direkt an cart_checkout übergeben. '
            . 'Ausnahme: War der Warenkorb vor dieser Anfrage bereits befüllt (AKTUELLER WARENKORB enthält Artikel die nicht Teil '
            . 'dieser Bestellung sind), dann alle Positionen auflisten und einmalig fragen: "Soll ich alle X Positionen bestellen?" '
            . 'Weitere Ausnahme — NICHT direkt bestellen: Rufe in DERSELBEN Nachricht WEDER cart_checkout NOCH cart_add_item auf, '
            . 'wenn (a) nicht alle angefragten bzw. aus einem Anhang extrahierten Positionen eindeutig einem Katalogartikel '
            . 'zugeordnet werden können (mindestens eine Position ist "nicht im Katalog gefunden" / hat keine SKU), ODER (b) zu '
            . 'einer Position noch eine erforderliche Angabe fehlt (z.B. Farbe/Variante) oder die SKU unklar ist — auch dann nicht, '
            . 'wenn der Kunde ausdrücklich "bestellen" sagt. In diesen Fällen: setze intent=ask_clarification, lasse tool_calls leer, '
            . 'liste zuordenbare und nicht zuordenbare Positionen getrennt auf (Tabelle mit Position, Produktname, SKU, Menge) und '
            . 'frage einmalig, ob die N zuordenbaren Positionen bestellt werden sollen. Erst nach ausdrücklicher Bestätigung des '
            . 'Kunden im nächsten Schritt cart_add_item + cart_checkout aufrufen. Lieber einmal zu viel nachfragen als '
            . 'unvollständig/falsch bestellen.';

        $instructions .= "\n\n"
            . 'BESTELLBESTÄTIGUNG (cart_checkout): Wenn du cart_checkout aufrufst, verfasse deine '
            . 'Bestätigung wie gewohnt vollständig in der Sprache der aktuellen Kundennachricht '
            . '(Anrede, Artikeltabelle, Hinweise, Verabschiedung, Signatur). Schreibe an die Stelle, '
            . 'an der die Bestellnummer stehen soll, EXAKT den Platzhalter '
            . OrderConfirmationFormatter::ORDER_NUMBER_PLACEHOLDER . ' (unverändert, inkl. der doppelten '
            . 'geschweiften Klammern) — erfinde NIEMALS selbst eine Bestellnummer; das System ersetzt den '
            . 'Platzhalter durch die echte Magento-Bestellnummer. Füge außerdem einen Satz in der Sprache '
            . 'des Kunden hinzu, dass er in Kürze eine Auftragsbestätigung per E-Mail erhält. Verwende den '
            . 'Platzhalter in response_text UND response_html an der jeweils passenden Stelle.';

        $instructions .= "\n\n"
            . 'PRIORITÄT DER AKTUELLEN ANFRAGE: Die neueste Kundennachricht (=== AKTUELLE ANFRAGE ===) hat '
            . 'IMMER höchste Priorität. Beantworte ausschließlich diese Anfrage — unabhängig davon, was in '
            . 'früheren Nachrichten des Gesprächsverlaufs besprochen wurde. Der Verlauf dient nur als Kontext, '
            . 'nicht als Aufgabe. Wenn die aktuelle Anfrage nach Produkten fragt, zeige Produkte. Wenn sie nach '
            . 'dem Warenkorb fragt, zeige den Warenkorb. Leite NIEMALS auf ein anderes Thema um, das nicht in '
            . 'der aktuellen Anfrage steht.';

        $instructions .= "\n\n"
            . 'ATTRIBUT-FILTER: Nutze search_products_by_filter NUR wenn der Kunde nach einem konkreten '
            . 'Attributwert filtert — also wenn er explizit einen Feldnamen und einen Wert nennt '
            . '(z.B. "alle Artikel wo Supplier Auto Order = Nein", "Artikel von Lieferant Zwernemann", '
            . '"Produkte mit Kategorie CP"). Für allgemeine Themensuchen wie "Broschüren zum Thema '
            . 'Temperatur" oder "Artikel über Flow" sind die RAG-Suchergebnisse im Kontext bereits '
            . 'das richtige Ergebnis — nutze diese direkt, KEIN search_products_by_filter. '
            . 'Attribute_code: Übernimm den Key exakt aus den Pinecone-Metadaten inkl. attr_-Präfix '
            . '(z.B. attr_bb_supplier_auto_order) — NUR wenn dieser Key in den RAG-Suchergebnissen '
            . 'sichtbar ist. Erfinde keine Attribut-Codes. '
            . 'Boolean-Attribute: Nein/no/false → "0", Ja/yes/true → "1". '
            . 'Textfelder als Teilstring übergeben — kein exakter Match nötig.';

        $instructions .= "\n\n"
            . 'Wenn die Nachricht eine automatische Abwesenheitsbenachrichtigung, ein Out-of-Office-Reply '
            . 'oder ein sonstiger maschinell generierter Auto-Responder ist (erkennbar an Formulierungen '
            . 'wie „Ich bin derzeit nicht erreichbar", „I am out of office", „Sono in ferie" o.ä.), '
            . 'setze intent = auto_reply und lasse response_text sowie response_html leer. '
            . 'Es wird dann KEINE Antwort gesendet.';

        return $instructions;
    }

    /**
     * Build the "ANSPRACHE & SIGNATUR" directive from backend config.
     * The form of address (formal/informal) and the shop name are configured
     * outside the prompt; here they become concrete, model-agnostic instructions.
     */
    private function buildAddressDirective(): string
    {
        $form = (string)$this->config->getValue(self::XML_PATH_ADDRESS_FORM);
        $form = $form !== '' ? $form : \Zwernemann\Chat\Model\Config\Source\AddressForm::FORMAL;

        // Shop name for the signature — fall back to the SMTP "From Name", then a neutral label.
        $shopName = trim((string)$this->config->getValue(self::XML_PATH_SHOP_NAME));
        if ($shopName === '') {
            $shopName = trim((string)$this->config->getValue(self::XML_PATH_FROM_NAME));
        }
        if ($shopName === '') {
            $shopName = 'Ihr Serviceteam';
        }

        if ($form === \Zwernemann\Chat\Model\Config\Source\AddressForm::INFORMAL) {
            return "=== ANSPRACHE & SIGNATUR (verbindlich) ===\n"
                . "Verwende durchgängig die lockere Anrede (Du/Dein).\n"
                . "Anrede: Beginne mit \"Hallo <Vorname>,\" wenn der Vorname in den KUNDENDATEN steht, "
                . "sonst mit \"Hallo,\".\n"
                . "Verabschiedung: Beende jede Nachricht mit einer freundlichen Grußformel (z. B. \"Viele Grüße\") "
                . "und in der Zeile darunter dem Shop-Namen als Signatur: \"" . $shopName . "\".\n"
                . "Diese Anrede und Verabschiedung gehören in JEDE Antwort (response_text und response_html).";
        }

        return "=== ANSPRACHE & SIGNATUR (verbindlich) ===\n"
            . "Verwende durchgängig die förmliche Anrede (Sie/Ihr).\n"
            . "Anrede: Beginne mit \"Sehr geehrte Frau <Nachname>,\" bzw. \"Sehr geehrter Herr <Nachname>,\" "
            . "wenn der Nachname und die Anrede aus den KUNDENDATEN eindeutig hervorgehen, "
            . "andernfalls mit dem neutralen \"Guten Tag,\".\n"
            . "Verabschiedung: Beende jede Nachricht mit \"Mit freundlichen Grüßen\" und in der Zeile darunter "
            . "dem Shop-Namen als Signatur: \"" . $shopName . "\".\n"
            . "Diese Anrede und Verabschiedung gehören in JEDE Antwort (response_text und response_html).";
    }

    /**
     * Build the user-facing message with full context.
     *
     * Text-type attachments (DOCX/XLSX raw XML) are embedded inline before the
     * current request section. PDF attachments are sent as Anthropic document blocks
     * (via buildDocumentBlocks()) and therefore not repeated here.
     *
     * @param array<int, array<string, mixed>> $orderHistory
     * @param array<int, array<string, mixed>> $ragResults
     * @param array<int, array<string, mixed>> $conversationHistory
     * @param ExtractedAttachment[]            $extractedAttachments
     */
    public function buildUserMessage(
        UnifiedMessageInterface $message,
        array $customerData,
        array $orderHistory,
        array $ragResults,
        array $conversationHistory = [],
        array $extractedAttachments = [],
        string $resolvedQuery = '',
        string $queryType = 'product',
        array $pendingClarification = []
    ): string {
        $parts = [];

        // Customer context
        $parts[] = '=== KUNDENDATEN ===';
        $parts[] = 'E-Mail: ' . ($message->getResolvedEmail() ?: $message->getCustomerIdentifier());
        if (!empty($customerData)) {
            $groupName = $customerData['group_name'] ?? ($customerData['group_id'] !== null ? 'Gruppe ' . $customerData['group_id'] : '');
            $parts[] = 'Name: ' . ($customerData['firstname'] ?? '') . ' ' . ($customerData['lastname'] ?? '')
                . ($groupName !== '' ? ' | Kundengruppe: ' . $groupName : '');
            $parts[] = 'Firma: ' . ($customerData['company'] ?? 'unbekannt');
        }

        // Customer shipping addresses with IDs (for address selection in orders)
        if (!empty($customerData['addresses'])) {
            $parts[] = '';
            $parts[] = '=== LIEFERADRESSEN DES KUNDEN ===';
            foreach ($customerData['addresses'] as $addr) {
                $addrId   = $addr['id'] ?? '?';
                $street   = is_array($addr['street'] ?? '') ? implode(', ', $addr['street']) : ($addr['street'] ?? '');
                $flags    = [];
                if ($addr['default_shipping'] ?? false) {
                    $flags[] = 'Standard-Lieferadresse';
                }
                if ($addr['default_billing'] ?? false) {
                    $flags[] = 'Standard-Rechnungsadresse';
                }
                $flagStr = $flags ? ' (' . implode(', ', $flags) . ')' : '';
                $parts[] = sprintf(
                    'ID %s: %s %s, %s %s, %s%s',
                    $addrId,
                    $addr['firstname'] ?? '',
                    $addr['lastname']  ?? '',
                    $street,
                    $addr['postcode']  ?? '',
                    $addr['city']      ?? '',
                    $flagStr
                );
            }
        }

        // Available payment methods + saved Vault tokens
        $paymentMethods = $customerData['payment_methods'] ?? [];
        if (!empty($paymentMethods)) {
            $parts[] = '';
            $parts[] = '=== VERFÜGBARE ZAHLARTEN ===';
            foreach ($paymentMethods as $m) {
                if (($m['type'] ?? '') === 'vault') {
                    $expires = !empty($m['expires']) ? ', läuft ab ' . $m['expires'] : '';
                    $parts[] = '- ' . $m['code'] . ': ' . $m['label'] . ' (gespeichert' . $expires . ')';
                } else {
                    $parts[] = '- ' . $m['code'] . ': ' . $m['label'];
                }
            }
        }

        $needsRag   = ($queryType === 'product');
        // Full order history (up to 10 orders with every line item) is a large block.
        // Include it inline only when the intent actually needs those line items:
        // reorder (Nachbestellung) or account_order (Tracking, Rechnung, Bestellstatus).
        // For everything else — product search, cart, address/account — a one-line note
        // that a history exists is enough; in product mode the LLM can still pull the
        // details on demand via get_order_history.
        $showOrders = in_array($queryType, ['reorder', 'account_order'], true);

        if ($showOrders && !empty($orderHistory)) {
            $parts[] = '';
            $parts[] = '=== BESTELLVERLAUF (letzte 10 Bestellungen) ===';
            foreach (array_slice($orderHistory, 0, 10) as $order) {
                $date  = substr($order['created_at'] ?? '', 0, 10);
                $total = number_format((float)($order['grand_total'] ?? 0), 2, ',', '.');
                $parts[] = sprintf(
                    'Bestellung #%s vom %s – %.2f EUR – Status: %s',
                    $order['increment_id'] ?? '?',
                    $date,
                    (float)($order['grand_total'] ?? 0),
                    $order['status'] ?? '?'
                );

                // List items of this order
                foreach ($order['items'] ?? [] as $item) {
                    $parts[] = sprintf(
                        '  • %s (SKU: %s) – %d Stück à %.2f EUR',
                        $item['name'] ?? '?',
                        $item['sku'] ?? '?',
                        (int)($item['qty_ordered'] ?? 0),
                        (float)($item['price'] ?? 0)
                    );
                }
            }
        } elseif ($showOrders) {
            $parts[] = '';
            $parts[] = '=== BESTELLVERLAUF ===';
            $parts[] = 'Keine Bestellungen gefunden.';
        } elseif (!empty($orderHistory)) {
            // Non-order intent: reference only, no line items (saves a large token cost).
            $parts[] = '';
            $parts[] = '=== BESTELLVERLAUF ===';
            $note = sprintf(
                'Es existiert ein Bestellverlauf (%d Bestellung%s) — aus Effizienzgründen bei '
                . 'dieser Anfrage nicht im Detail geladen, da kein Bestellbezug erkannt wurde.',
                count($orderHistory),
                count($orderHistory) === 1 ? '' : 'en'
            );
            if ($queryType === 'product') {
                $note .= ' Falls der Kunde doch nach einer Nachbestellung, Sendungsverfolgung oder '
                    . 'Rechnung fragt, rufe get_order_history auf, um die Bestelldetails zu laden.';
            }
            $parts[] = $note;
        } else {
            $parts[] = '';
            $parts[] = '=== BESTELLVERLAUF ===';
            $parts[] = 'Keine Bestellungen vorhanden.';
        }

        // Active cart contents
        $parts[] = '';
        if (!empty($customerData['cart_items']['items'])) {
            $parts[] = '=== AKTUELLER WARENKORB ===';
            foreach ($customerData['cart_items']['items'] as $item) {
                $parts[] = sprintf(
                    '• %s (SKU: %s) – %d Stück à %.2f EUR = %.2f EUR',
                    $item['name'], $item['sku'], $item['qty'],
                    (float)$item['price'], (float)$item['row_total']
                );
            }
            $parts[] = sprintf('Zwischensumme: %.2f EUR', (float)($customerData['cart_items']['subtotal'] ?? 0));
        } else {
            $parts[] = '=== AKTUELLER WARENKORB ===';
            $parts[] = 'Warenkorb ist leer.';
        }

        // Conversational context hint: resolved product from ConversationalQueryBuilder
        if ($resolvedQuery !== '') {
            $parts[] = '';
            $parts[] = '=== KONVERSATIONSKONTEXT ===';
            $parts[] = 'Aufgelöster Suchbegriff: "' . $resolvedQuery . '"';
        }

        // Open clarification from the previous turn: the customer's current message
        // answers THIS question — it outranks any resolved search term above.
        if (!empty($pendingClarification['question'])) {
            $parts[] = '';
            $parts[] = '=== OFFENE RÜCKFRAGE (vorheriger Zug) ===';
            $parts[] = 'Du hast dem Kunden zuletzt diese Rückfrage gestellt:';
            $parts[] = '"' . $this->truncateHistoryMessage(
                trim((string)$pendingClarification['question']),
                $this->getHistoryMaxChars()
            ) . '"';
            if (!empty($pendingClarification['items'])) {
                $parts[] = 'Dabei wurden folgende Bestellpositionen extrahiert:';
                foreach ($pendingClarification['items'] as $item) {
                    $qty  = $item['qty'] ?? 0;
                    $qty  = (float)$qty === (float)(int)$qty ? (string)(int)$qty : (string)$qty;
                    $sku  = trim((string)($item['sku'] ?? ''));
                    $opts = trim((string)($item['options_text'] ?? ''));
                    $parts[] = '• ' . $qty . 'x ' . ($item['name'] ?? '?')
                        . ($sku !== '' ? ' (SKU: ' . $sku . ')' : '')
                        . ($opts !== '' ? ' [Konfigurierbar — ' . $opts . ' → Variante in options angeben]' : '');
                }
            }
            $parts[] = 'Die aktuelle Nachricht des Kunden ist die Antwort auf diese Rückfrage. '
                . 'Bei Zustimmung ("ja", "bitte", "ok") führe die vorgeschlagene Aktion mit genau diesen '
                . 'Positionen aus — gleiche SKUs über die RAG-Suchergebnisse ab. Wechsle NICHT zu einem '
                . 'früheren Gesprächsthema.';
        }

        // RAG results
        if (!empty($ragResults)) {
            $parts[] = '';
            $parts[] = '=== PASSENDE PRODUKTE AUS DEM KATALOG (RAG) ===';
            foreach ($ragResults as $result) {
                $m = $result['metadata'] ?? [];

                $effectivePrice = (float)($m['price'] ?? 0);
                $listPrice      = (float)($m['list_price'] ?? $effectivePrice);
                $priceLabel     = number_format($effectivePrice, 2, ',', '.');
                if ($listPrice > $effectivePrice + 0.005) {
                    $priceLabel .= ' (Ihr Preis; Listenpreis: ' . number_format($listPrice, 2, ',', '.') . ' EUR)';
                }
                $parts[] = sprintf(
                    '• %s (SKU: %s) – %s EUR | Score: %.3f',
                    $m['name'] ?? '?',
                    $m['sku']  ?? '?',
                    $priceLabel,
                    (float)($result['score'] ?? 0)
                );
                if (!empty($m['short_desc'])) {
                    $parts[] = '  ' . $m['short_desc'];
                }
                if (!empty($m['description'])) {
                    $parts[] = '  ' . mb_substr($m['description'], 0, 400);
                }
                if (!empty($m['options'])) {
                    $parts[] = '  Konfigurierbare Optionen: ' . $m['options'];
                }
                if (!empty($m['tier_prices'])) {
                    $tierLines = [];
                    foreach ($m['tier_prices'] as $tp) {
                        if ((float)$tp['qty'] > 1.0) {
                            $tierLines[] = 'ab ' . (int)$tp['qty'] . ' Stück → '
                                . number_format((float)$tp['price'], 2, ',', '.') . ' EUR';
                        }
                    }
                    if ($tierLines) {
                        $parts[] = '  Staffelpreise: ' . implode(' | ', $tierLines);
                    }
                }
                if (isset($m['in_stock'])) {
                    if (isset($m['manage_stock']) && !$m['manage_stock']) {
                        $parts[] = '  Lagerbestand: Dauerhaft bestellbar (kein Bestandsmanagement)';
                    } elseif (!empty($m['variants'])) {
                        // Configurable product — show per-variant stock breakdown
                        $inParts     = [];
                        $outParts    = [];
                        $anyUnmanaged = false;
                        foreach ($m['variants'] as $v) {
                            $varManage = $v['manage_stock'] ?? ($v['qty'] !== null);
                            if ($v['in_stock']) {
                                if (!$varManage) {
                                    $inParts[]    = $v['option'] . ': bestellbar (kein Bestandsmanagement)';
                                    $anyUnmanaged = true;
                                } else {
                                    $inParts[] = $v['option'] . ': ' . (int)$v['qty'];
                                }
                            } else {
                                $outParts[] = $v['option'] . ': nicht vorrätig';
                            }
                        }
                        if ($m['in_stock']) {
                            $detail = implode(', ', $inParts);
                            if (!empty($outParts)) {
                                $detail .= ' | ' . implode(', ', $outParts);
                            }
                            if ($anyUnmanaged && (int)$m['stock_qty'] === 0) {
                                $parts[] = '  Lagerbestand: Bestellbar – Varianten: ' . $detail;
                            } else {
                                $parts[] = sprintf(
                                    '  Lagerbestand: %d Stück gesamt – Varianten: %s',
                                    (int)$m['stock_qty'],
                                    $detail
                                );
                            }
                        } else {
                            $parts[] = '  Lagerbestand: Alle Varianten ausverkauft';
                        }
                    } elseif ($m['in_stock']) {
                        $parts[] = sprintf('  Lagerbestand: %d Stück verfügbar', (int)$m['stock_qty']);
                    } else {
                        $parts[] = '  Lagerbestand: Nicht vorrätig (ausverkauft)';
                    }
                }
                if (!empty($m['product_id'])) {
                    $parts[] = '  Produkt-ID: product_' . $m['product_id']
                        . ' — für product_ids_to_show und <img src="cid:product_' . $m['product_id'] . '"> im HTML verwenden';
                }
                if (!empty($m['image_url'])) {
                    $parts[] = '  Bild-URL: ' . $m['image_url'];
                }
                if (!empty($m['attr_labels'])) {
                    $parts[] = '  Attribute: ' . $m['attr_labels'];
                }
            }

            // Provide a consolidated list of all product IDs so the LLM can reference them
            // when filling product_ids_to_show and when listing products in the text response.
            $allPids = array_values(array_filter(array_map(
                fn($r) => isset($r['metadata']['product_id'])
                    ? 'product_' . $r['metadata']['product_id']
                    : null,
                $ragResults
            )));
            if ($allPids) {
                $parts[] = '';
                $parts[] = '=== PRODUKT-IDs FÜR product_ids_to_show ===';
                $parts[] = 'Alle ' . count($allPids) . ' IDs MÜSSEN in product_ids_to_show stehen: '
                    . implode(', ', $allPids);
            }
        }

        // Embed processed attachments in the prompt
        foreach ($extractedAttachments as $attachment) {
            if ($attachment->getBlockType() === 'text') {
                // DOCX/XLSX: raw XML — LLM interprets structure
                $parts[] = '';
                $parts[] = '=== Anhang: ' . $attachment->getFilename() . ' (OOXML) ===';
                $parts[] = $attachment->getContent();
                $parts[] = '===';
            } elseif ($attachment->getBlockType() === 'warning') {
                // Legacy format — tell the LLM so it can inform the customer
                $parts[] = '';
                $parts[] = '=== Hinweis zu Anhang: ' . $attachment->getFilename() . ' ===';
                $parts[] = $attachment->getContent();
                $parts[] = '===';
            }
            // blockType='document' (PDF) is sent as an Anthropic document block — not embedded here
        }

        // Tool catalog — restrict to relevant tools per query type to reduce token count
        $toolAllowList = match($queryType) {
            'reorder'         => ['reorder_from_history', 'cart_add_item', 'cart_update_item', 'cart_remove_item', 'cart_checkout', 'get_order_history', 'redirect_to_store'],
            'account_order'   => ['get_order_history', 'get_order_detail', 'get_shipment_tracking', 'get_invoice', 'redirect_to_store'],
            'account_address' => ['get_shipping_addresses', 'add_shipping_address', 'set_order_shipping_address', 'redirect_to_store'],
            'account_general' => ['get_account_info', 'update_account_info', 'toggle_newsletter', 'get_wishlist', 'wishlist_add_item', 'wishlist_remove_item', 'wishlist_move_to_cart', 'set_stock_notification', 'apply_coupon_code', 'remove_coupon_code', 'redirect_to_store'],
            'cart'            => ['cart_add_item', 'cart_update_item', 'cart_remove_item', 'cart_checkout', 'apply_coupon_code', 'remove_coupon_code', 'redirect_to_store'],
            default           => [], // product: all tools
        };
        $toolCatalog = $this->toolRegistry->buildToolCatalog(
            (int)($customerData['store_id'] ?? 0),
            $toolAllowList
        );
        if ($toolCatalog !== '') {
            $parts[] = '';
            $parts[] = $toolCatalog;
        }

        // The actual customer request — attachment-only emails carry no body text,
        // so make explicit that the content lives in the attached documents.
        $parts[] = '';
        $parts[] = '=== AKTUELLE ANFRAGE DES KUNDEN ===';
        $parts[] = trim($message->getContentText()) !== ''
            ? $message->getContentText()
            : '(Keine Textnachricht — der Kunde hat nur Dateianhänge gesendet. Siehe Anhangsinhalt.)';

        return implode("\n", $parts);
    }

    /**
     * Extract document blocks (PDFs) from processed attachments for the Anthropic API.
     *
     * @param ExtractedAttachment[] $extractedAttachments
     * @return array<int, array{media_type: string, data: string}>
     */
    public function buildDocumentBlocks(array $extractedAttachments): array
    {
        $blocks = [];
        foreach ($extractedAttachments as $attachment) {
            if ($attachment->getBlockType() === 'document') {
                $blocks[] = [
                    'media_type' => $attachment->getMediaType(),
                    'data'       => $attachment->getContent(),
                ];
            }
        }
        return $blocks;
    }

    /**
     * Build a native multi-turn messages array for the Anthropic API.
     *
     * Historical exchanges become alternating user/assistant turns (up to 4 messages,
     * configurable chars each — first+last half kept when truncated). The current message
     * with full context (customer data, orders, RAG) is appended as the final user turn —
     * without the inline history section, since history is expressed via the messages array.
     *
     * @param array<int, array<string, mixed>> $orderHistory
     * @param array<int, array<string, mixed>> $ragResults
     * @param array<int, array<string, mixed>> $conversationHistory
     * @param ExtractedAttachment[]            $extractedAttachments
     * @return array<int, array{role: string, content: string}>
     */
    public function buildMessages(
        UnifiedMessageInterface $message,
        array $customerData,
        array $orderHistory,
        array $ragResults,
        array $conversationHistory = [],
        array $extractedAttachments = [],
        string $resolvedQuery = '',
        bool $degraded = false,
        string $queryType = 'product',
        array $pendingClarification = []
    ): array {
        $messages = [];
        $lastRole = null;

        $maxChars = $this->getHistoryMaxChars();
        // cart/reorder turns continue a product conversation (confirmations,
        // quantity changes) and need the same history depth as product queries.
        $turns    = in_array($queryType, ['product', 'cart', 'reorder'], true)
            ? $this->getHistoryTurnsMain()
            : $this->getHistoryTurnsAccount();
        foreach (array_slice($conversationHistory, -$turns) as $msg) {
            $role = ($msg['direction'] ?? '') === 'inbound' ? 'user' : 'assistant';
            $raw  = $this->stripDegradedNote($msg['content_text'] ?? '');
            $text = $this->truncateHistoryMessage($raw, $maxChars);

            if ($role === $lastRole && !empty($messages)) {
                // Merge consecutive same-role messages (edge case: two inbound in a row)
                $last      = array_pop($messages);
                $messages[] = ['role' => $role, 'content' => $last['content'] . "\n\n" . $text];
            } else {
                $messages[] = ['role' => $role, 'content' => $text];
            }
            $lastRole = $role;
        }

        // Current message: full context but WITHOUT inline history section (pass [] for history)
        $currentContent = $this->buildUserMessage(
            $message, $customerData, $orderHistory, $ragResults, [], $extractedAttachments, $resolvedQuery, $queryType, $pendingClarification
        );

        if ($degraded) {
            $currentContent .= "\n\n[SYSTEM: The AI search subsystem ran at reduced capacity for this request. "
                . "Please append one brief, friendly sentence in the customer's language at the very end of your response, "
                . "informing them that the product search may be incomplete due to temporary system load. "
                . "Start that sentence with the exact string '" . self::DEGRADED_MARKER . "' (it will be hidden from the customer — do not translate or omit it). "
                . "Do not let it overshadow the main response.]";
        }

        if ($lastRole === 'user' && !empty($messages)) {
            $last       = array_pop($messages);
            $messages[] = ['role' => 'user', 'content' => $last['content'] . "\n\n" . $currentContent];
        } else {
            $messages[] = ['role' => 'user', 'content' => $currentContent];
        }

        $this->pipelineLogger->section('LLM CONTEXT BUILD');
        $this->pipelineLogger->raw('System prompt', $this->buildSystemPrompt());
        $this->pipelineLogger->data('Messages array (' . count($messages) . ' turns)', $messages);

        return $messages;
    }

    /**
     * Strip the degraded-notice marker and everything after it from a stored response.
     * Called when loading history turns and when preparing the outbound text for storage.
     */
    public function stripDegradedNote(string $text): string
    {
        $pos = strpos($text, self::DEGRADED_MARKER);
        if ($pos === false) {
            return $text;
        }
        return rtrim(substr($text, 0, $pos));
    }

    private function getHistoryMaxChars(): int
    {
        $v = (int)$this->config->getValue(
            self::XML_PATH_MAX_CHARS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 2000;
    }

    private function getHistoryTurnsMain(): int
    {
        $v = (int)$this->config->getValue(
            self::XML_PATH_HISTORY_TURNS,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 4;
    }

    private function getHistoryTurnsAccount(): int
    {
        $v = (int)$this->config->getValue(
            self::XML_PATH_HISTORY_TURNS_ACCOUNT,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        return $v > 0 ? $v : 2;
    }

    private function truncateHistoryMessage(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }
        $half = (int)($maxChars / 2);
        return mb_substr($text, 0, $half) . '…' . mb_substr($text, -$half);
    }
}
