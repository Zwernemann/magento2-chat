<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Llm;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;
use Zwernemann\Chat\Api\LlmClientInterface;
use Zwernemann\Chat\Model\Attachment\AttachmentProcessor;
use Zwernemann\Chat\Model\Attachment\ExtractedAttachment;
use Zwernemann\Chat\Model\PipelineLogger;
use Zwernemann\Chat\Api\ErrorNotifierInterface;
use Zwernemann\Chat\Model\Rag\ProductIndexer;

/**
 * Sends the full context to the configured LLM provider and interprets the structured response.
 *
 * Uses provider-native function/tool calling to guarantee valid JSON output —
 * no client-side JSON parsing fragility. The response schema is enforced by the API.
 */
class IntentProcessor
{
    private const TOOL_NAME = 'submit_response';

    private const TOOL_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'intent' => [
                'type'        => 'string',
                'enum'        => ['auto_reply', 'ask_clarification', 'other'],
                'description' => 'auto_reply: automatischer Out-of-Office-Responder — response_text/html leer lassen, keine Antwort wird gesendet. ask_clarification: LLM stellt eine Rückfrage ohne Tool-Ausführung. other: alle anderen Fälle inkl. Tool-Aufrufe.',
            ],
            'confidence' => ['type' => 'number'],
            'tool_calls' => [
                'type'        => 'array',
                'description' => 'Geordnete Liste von Magento-Aktionen die ausgeführt werden sollen. Leer lassen bei reinen Textantworten und Rückfragen.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'name'   => ['type' => 'string', 'description' => 'Tool-Name aus dem Katalog (z.B. cart_add_item, cart_checkout, get_order_history)'],
                        'params' => ['type' => 'object', 'description' => 'Tool-Parameter gemäß Katalog-Schema'],
                    ],
                    'required' => ['name', 'params'],
                ],
            ],
            'response_text' => [
                'type'        => 'string',
                'description' => 'Antwort im Klartext für E-Mail-Plaintext. Keine HTML-Tags.',
            ],
            'response_html' => [
                'type'        => 'string',
                'description' => 'Antwort als HTML für E-Mail-Body und WebChat. Pflicht: echte HTML-Tags verwenden — <ul><li> für Listen, <table><tr><td> für Produktübersichten ab 3 Positionen. Produktbilder als <img src="cid:product_ID"> einbetten. Niemals rohen Plaintext ohne HTML-Struktur ausgeben.',
            ],
            'product_ids_to_show' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'IDs of products to show images for, e.g. ["product_42","product_17"]',
            ],
            'extracted_items' => [
                'type'        => 'array',
                'description' => 'Aus Anhängen oder der Nachricht extrahierte Bestellpositionen. IMMER befüllen, '
                    . 'wenn Bestellpositionen erkannt wurden — insbesondere bei intent=ask_clarification, damit die '
                    . 'Positionen für den nächsten Gesprächszug erhalten bleiben. Leer lassen, wenn keine Positionen erkannt wurden.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'sku'  => ['type' => 'string', 'description' => 'SKU aus Dokument oder RAG-Treffer; leer wenn unbekannt'],
                        'name' => ['type' => 'string', 'description' => 'Produktbezeichnung aus dem Dokument'],
                        'qty'  => ['type' => 'number', 'description' => 'Menge'],
                    ],
                    'required' => ['name', 'qty'],
                ],
            ],
        ],
        'required' => ['intent', 'confidence', 'tool_calls', 'response_text', 'response_html', 'product_ids_to_show'],
    ];

    /**
     * Return the tool schema tailored to the channel's needs.
     *
     * HTML-only channels (WebChat) do not need the model to author response_text —
     * dropping it from properties + required halves the output body the LLM writes.
     * The plaintext those channels still need internally is derived from the HTML
     * afterwards (see process()).
     *
     * @return array<string, mixed>
     */
    private static function toolSchema(bool $needsPlainText): array
    {
        $schema = self::TOOL_SCHEMA;
        if (!$needsPlainText) {
            unset($schema['properties']['response_text']);
            $schema['required'] = array_values(
                array_filter($schema['required'], static fn($f) => $f !== 'response_text')
            );
        }
        return $schema;
    }

    /** Hard cap for persisted extracted order items (very long parts lists). */
    private const MAX_EXTRACTED_ITEMS = 100;

    public function __construct(
        private readonly LlmClientInterface     $llm,
        private readonly ContextBuilder         $contextBuilder,
        private readonly AttachmentProcessor    $attachmentProcessor,
        private readonly ProductIndexer         $productIndexer,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface  $storeManager,
        private readonly ErrorNotifierInterface $errorNotifier,
        private readonly LoggerInterface        $logger,
        private readonly PipelineLogger         $pipelineLogger
    ) {}

    /**
     * @param array<string, mixed>             $customerData
     * @param array<int, array<string, mixed>> $orderHistory
     * @param array<int, array<string, mixed>> $ragResults
     * @param array<int, array<string, mixed>> $conversationHistory
     * @param array<int, array{filename: string, content_type: string, data: string}> $rawAttachments
     * @return array<string, mixed>
     */
    /**
     * @param array<int, ExtractedAttachment> $preExtractedAttachments
     *   When non-empty, skips AttachmentProcessor::processAll() to avoid double-processing.
     *   Pass pre-extracted attachments from MessageProcessor (built before the RAG search).
     */
    public function process(
        UnifiedMessageInterface $message,
        array $customerData,
        array $orderHistory,
        array $ragResults,
        array $conversationHistory = [],
        array $rawAttachments = [],
        array $preExtractedAttachments = [],
        string $resolvedQuery = '',
        bool $degraded = false,
        string $queryType = 'product',
        array $pendingClarification = [],
        bool $needsPlainText = true
    ): array {
        $extractedAttachments = !empty($preExtractedAttachments)
            ? $preExtractedAttachments
            : $this->attachmentProcessor->processAll($rawAttachments);
        $documentBlocks       = $this->contextBuilder->buildDocumentBlocks($extractedAttachments);

        if (!empty($extractedAttachments)) {
            $this->pipelineLogger->section('ATTACHMENTS');
            $this->pipelineLogger->data('Processed attachments', array_map(
                fn($a) => ['file' => $a->getFilename(), 'type' => $a->getBlockType()],
                $extractedAttachments
            ));
        }

        $systemPrompt = $this->contextBuilder->buildSystemPrompt();
        $messages     = $this->contextBuilder->buildMessages(
            $message, $customerData, $orderHistory, $ragResults, $conversationHistory, $extractedAttachments, $resolvedQuery, $degraded, $queryType, $pendingClarification
        );

        try {
            $result = $this->llm->chatWithTool(
                $messages,
                $systemPrompt,
                self::TOOL_NAME,
                self::toolSchema($needsPlainText),
                [],
                $documentBlocks
            );

            if (empty($result)) {
                $this->logger->error('Zwernemann_Chat: chatWithTool returned empty result — using fallback.');
                return $this->fallbackResult('Empty tool_use response from LLM');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Zwernemann_Chat: LLM processing failed – ' . $e->getMessage());
            $this->errorNotifier->notify('LLM-API-Fehler', $e->getMessage());
            return $this->fallbackResult($e->getMessage());
        }

        // Ensure required keys exist with safe defaults
        $result['tool_calls']          ??= [];
        $result['product_ids_to_show'] ??= [];
        $result['extracted_items']     = $this->sanitizeExtractedItems($result['extracted_items'] ?? []);

        // HTML-only channels (WebChat) did not ask the model for response_text — derive it
        // from the raw HTML (before image injection, so it carries no <img> markup). Keeps
        // downstream text handling, DB history (content_text) and the JS fallback working.
        if (!$needsPlainText) {
            $result['response_text'] = self::htmlToPlainText($result['response_html'] ?? '');
        }

        $this->logger->info('[IntentProcessor] product_ids_to_show from LLM', [
            'count' => count($result['product_ids_to_show']),
            'ids'   => $result['product_ids_to_show'],
        ]);

        $this->pipelineLogger->section('LLM STRUCTURED RESULT (tool_use output)');
        $logResult = $result;
        unset($logResult['response_html']);
        $this->pipelineLogger->data('Parsed LLM result (without response_html)', $logResult);
        $this->logger->info('[IntentProcessor] tool_calls from LLM', [
            'count'      => count($result['tool_calls']),
            'tool_names' => array_column($result['tool_calls'], 'name'),
        ]);
        $this->pipelineLogger->raw('response_text (plain)', $result['response_text'] ?? '');
        $this->pipelineLogger->raw('response_html (before image injection)', $result['response_html'] ?? '');

        // Enrich HTML with inline product images
        $inlineImages = [];
        $html = $result['response_html'] ?? $result['response_text'] ?? '';
        // Resolved product cards (image successfully fetched), in product_ids_to_show
        // order. associateProductImages() then places each next to the product it
        // belongs to instead of piling them all up at the end of the mail.
        $cards = [];

        foreach ($result['product_ids_to_show'] as $productKey) {
            $this->logger->info('[IntentProcessor] Processing product image', [
                'key'           => $productKey,
                'rag_item_count'=> count($ragResults),
                'rag_pids'      => array_map(
                    fn($r) => 'product_' . ($r['metadata']['product_id'] ?? '?'),
                    array_slice($ragResults, 0, 10)
                ),
            ]);

            foreach ($ragResults as $ragItem) {
                $meta = $ragItem['metadata'] ?? [];
                $pid  = 'product_' . ($meta['product_id'] ?? '');
                if ($pid !== $productKey) {
                    continue;
                }

                // Use image_url from Pinecone metadata; fall back to live Magento catalog lookup
                $imageUrl = $meta['image_url'] ?? null;
                $this->logger->info('[IntentProcessor] RAG metadata for ' . $productKey, [
                    'sku'       => $meta['sku'] ?? '?',
                    'image_url' => $imageUrl ?? '(empty)',
                ]);
                if (!$imageUrl) {
                    $imageUrl = $this->resolveImageUrlFromCatalog($meta['sku'] ?? '');
                    if ($imageUrl) {
                        $this->logger->info('[IntentProcessor] Resolved image URL from catalog for ' . $productKey
                            . ' (Pinecone metadata missing image_url — re-index to cache it).');
                    } else {
                        $this->logger->warning('[IntentProcessor] No image available for ' . $productKey
                            . ' (SKU: ' . ($meta['sku'] ?? '?') . '). Removing broken img tag from HTML.');
                        // Remove the broken <img> tag so the email client shows nothing instead of alt text
                        $html = preg_replace(
                            '/<img[^>]+src=["\']cid:' . preg_quote($productKey, '/') . '["\'][^>]*>/i',
                            '',
                            $html
                        );
                        break;
                    }
                }

                // CID matches what the LLM outputs: cid:product_42
                $cid       = 'product_' . ($meta['product_id'] ?? md5($productKey));
                $imageData = $this->fetchImageAsBase64($imageUrl);
                if ($imageData) {
                    $inlineImages[$cid] = [
                        'cid'  => $cid,
                        'data' => $imageData['data'],
                        'mime' => $imageData['mime'],
                    ];
                    // Replace raw image URL in HTML if LLM happened to include it
                    $html = str_replace($imageUrl, 'cid:' . $cid, $html);
                    // Defer placement to associateProductImages() so the image lands next
                    // to its product (inline in the table row) rather than in a detached
                    // block — and so products the response never references are skipped.
                    $cards[] = [
                        'cid'   => $cid,
                        'sku'   => (string)($meta['sku'] ?? ''),
                        'name'  => (string)($meta['name'] ?? $productKey),
                        'price' => (float)($meta['price'] ?? 0),
                    ];
                } else {
                    // Fetch failed — remove broken img tag
                    $html = preg_replace(
                        '/<img[^>]+src=["\']cid:' . preg_quote($cid, '/') . '["\'][^>]*>/i',
                        '',
                        $html
                    );
                }
                break;
            }
        }

        // Place each resolved image next to the product it belongs to.
        $html = self::associateProductImages($html, $cards);

        // Cap every CID product image to 200px wide and force height:auto so email
        // clients keep the aspect ratio instead of stretching the original dimensions.
        $html = self::normalizeProductImageSizing($html);

        $result['response_html'] = $html;
        $result['inline_images'] = $inlineImages;
        return $result;
    }

    /**
     * Associates resolved product images with the products actually shown in the HTML.
     *
     * Without this, every product in product_ids_to_show — which for a product listing
     * or a parsed order can be the whole RAG window — would dump a detached image card at
     * the end of the mail, leaving a wall of pictures with no relation to the table above.
     *
     * For each card (already de-duplicated, image fetched) it picks the closest placement:
     *   1. LLM already wrote <img src="cid:..."> inline           → leave it untouched;
     *   2. the SKU appears as its own table cell                  → inject a thumbnail into
     *      that row's SKU cell, so the picture sits with its product;
     *   3. the SKU or product name appears elsewhere in the text  → append a labelled card,
     *      ordered by first appearance so cards follow the listing order;
     *   4. the product is not referenced at all                   → skip it, so unrelated
     *      RAG hits never pile up as images.
     *
     * @param array<int, array{cid: string, sku: string, name: string, price: float}> $cards
     */
    public static function associateProductImages(string $html, array $cards): string
    {
        $append = []; // [{pos, html}] for products referenced but not in a SKU cell

        foreach ($cards as $card) {
            $cid  = (string)($card['cid'] ?? '');
            $sku  = trim((string)($card['sku'] ?? ''));
            $name = trim((string)($card['name'] ?? ''));

            // 1. Already placed inline by the LLM — nothing to do.
            if ($cid === '' || str_contains($html, 'cid:' . $cid)) {
                continue;
            }

            // 2. Inject a thumbnail into the row whose SKU cell holds this SKU.
            if ($sku !== '') {
                $thumb   = '<img src="cid:' . $cid . '" alt="' . htmlspecialchars($name)
                    . '" width="60" style="max-width:60px;height:auto;vertical-align:middle;margin-right:6px;">';
                $count   = 0;
                $newHtml = preg_replace(
                    '/(<td\b[^>]*>)(\s*' . preg_quote($sku, '/') . '\s*)(<\/td>)/',
                    '${1}' . $thumb . '${2}${3}',
                    $html,
                    1,
                    $count
                );
                if ($count > 0 && $newHtml !== null) {
                    $html = $newHtml;
                    continue;
                }
            }

            // 3. Referenced elsewhere (SKU or name in the text) — append a labelled card.
            $pos = false;
            if ($sku !== '') {
                $pos = mb_strpos($html, $sku);
            }
            if ($pos === false && $name !== '') {
                $pos = mb_strpos($html, $name);
            }
            if ($pos === false) {
                // 4. Not referenced anywhere — don't dump an unrelated image.
                continue;
            }

            $priceF   = number_format((float)($card['price'] ?? 0), 2, ',', '.');
            $append[] = [
                'pos'  => $pos,
                'html' => sprintf(
                    '<div style="margin:10px 0;padding:10px;border:1px solid #eee;overflow:hidden;">'
                    . '<img src="cid:%s" alt="%s" width="200" style="max-width:200px;height:auto;float:left;margin-right:10px;">'
                    . '<strong>%s</strong><br>SKU: %s<br>Preis: %s EUR</div>',
                    $cid,
                    htmlspecialchars($name),
                    htmlspecialchars($name),
                    htmlspecialchars($sku),
                    $priceF
                ),
            ];
        }

        if (!empty($append)) {
            usort($append, static fn($a, $b) => $a['pos'] <=> $b['pos']);
            foreach ($append as $item) {
                $html .= $item['html'];
            }
        }

        return $html;
    }

    /**
     * Normalises every inline CID product image to a capped width with a fluid height,
     * so email clients render the product photo at its natural aspect ratio.
     *
     * The squashed-image bug: the LLM emits an <img> with no width but *some* height
     * constraint — a fixed style height (style="height:48px"), a height="48" attribute,
     * or a cap (style="max-height:60px"). The width cap (a hard width="200", needed
     * because some clients ignore max-width) then forces a 200px-wide box while the
     * height constraint pins the height independently, and the photo is stretched to
     * fill the mismatch (200×48, 200×60, …).
     *
     * The only way to guarantee the aspect ratio is to let the *width* govern the size
     * and keep the height fully fluid. So every author height — height, min-height and
     * max-height, whether inline style or HTML attribute — is stripped and replaced by a
     * single height:auto. height:auto must be inline (not only in the <head> <style>
     * block) because forwarding clients (Gmail/Outlook) strip that block and mobile
     * clients apply it unreliably. "line-height" and other properties are left intact.
     *
     * Thumbnails injected by associateProductImages() already carry width="60" +
     * height:auto, so their explicit width is preserved and they pass through unchanged.
     */
    public static function normalizeProductImageSizing(string $html): string
    {
        return preg_replace_callback(
            '/<img([^>]+src=["\']cid:[^"\']+["\'][^>]*)>/i',
            static function (array $m): string {
                $attrs = $m[1];
                // Strip a trailing self-closing slash (e.g. <img ... />). Some models emit
                // XHTML-style tags; appending width/style after the "/" would otherwise
                // produce malformed markup like <img ... / style="...">.
                $attrs = preg_replace('#\s*/\s*$#', '', $attrs);
                if (!preg_match('/\bwidth\s*=/i', $attrs)) {
                    $attrs .= ' width="200"';
                }
                // Drop any fixed height attribute — combined with the width cap it squashes
                // the image (width="200" + height="48" → a stretched 200×48 sliver).
                $attrs = preg_replace('/\s*\bheight\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attrs);
                if (preg_match('/\bstyle\s*=\s*["\']([^"\']*)["\']/', $attrs, $s)) {
                    $style = $s[1];
                    // Strip every height-family declaration (height / min-height /
                    // max-height); any of them fights the width cap and squashes the photo.
                    // "line-height" and the rest survive (the (^|;) anchor matches whole
                    // declarations only).
                    $style = preg_replace('/(^|;)\s*(?:min-|max-)?height\s*:[^;]*/i', '$1', $style);
                    $style = preg_replace('/;{2,}/', ';', (string)$style);
                    $style = trim((string)$style, '; ');
                    if (!str_contains($style, 'max-width')) {
                        $style = $style === '' ? 'max-width:200px' : $style . ';max-width:200px';
                    }
                    // Pin the height to auto so the width cap alone drives the size.
                    $style .= ';height:auto';
                    $attrs = preg_replace('/\bstyle\s*=\s*["\'][^"\']*["\']/', 'style="' . $style . '"', $attrs);
                } else {
                    $attrs .= ' style="max-width:200px;height:auto"';
                }
                return '<img' . $attrs . '>';
            },
            $html
        ) ?? $html;
    }

    /**
     * Derive a readable plaintext body from the model's HTML.
     *
     * Used for HTML-only channels (WebChat) whose schema omits response_text: instead of
     * paying the LLM to write the answer a second time as plaintext, we convert the HTML.
     * The result feeds the DB history (content_text, re-sent to the LLM next turn) and the
     * JS plaintext fallback — neither is displayed as-is, so structural fidelity over pixel
     * fidelity is enough. Block-level closers become newlines and table cells become spaces
     * so the text keeps its line/row structure; <img> tags carry no text and vanish with
     * strip_tags. Any {{ORDER_NUMBER}} placeholder is plain text and is preserved.
     */
    public static function htmlToPlainText(string $html): string
    {
        // Block-level boundaries → newline; table cells → space, so rows don't run together.
        $text = preg_replace('#</(p|div|li|tr|h[1-6]|table|thead|tbody|ul|ol)>#i', "$0\n", $html);
        $text = preg_replace('#<br\s*/?>#i', "\n", (string)$text);
        $text = preg_replace('#</(td|th)>#i', "$0 ", (string)$text);
        $text = html_entity_decode(strip_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking spaces (from &nbsp;) become ordinary spaces so they collapse below.
        $text = str_replace("\u{00A0}", ' ', $text);
        // Normalise whitespace: trim each line, drop trailing spaces, collapse blank runs.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/ *\n */', "\n", (string)$text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string)$text);
        return trim((string)$text);
    }

    /**
     * Fetch the product image URL from the Magento catalog by SKU.
     * Used as fallback when image_url is missing from Pinecone metadata (old vectors).
     */
    private function resolveImageUrlFromCatalog(string $sku): ?string
    {
        if ($sku === '') {
            return null;
        }
        try {
            $product = $this->productRepository->get($sku);
            $image   = $product->getImage();
            if (!$image || $image === 'no_selection') {
                return null;
            }
            $mediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
            return rtrim($mediaUrl, '/') . '/catalog/product' . $image;
        } catch (\Throwable $e) {
            $this->logger->warning('[IntentProcessor] Catalog image lookup failed for SKU ' . $sku . ': ' . $e->getMessage());
            return null;
        }
    }

    /** @return array{data: string, mime: string}|null */
    private function fetchImageAsBase64(string $url): ?array
    {
        try {
            $opts = [
                'http' => [
                    'timeout'       => 10,
                    'ignore_errors' => true,
                    'user_agent'    => 'Zwernemann_Chat/1.0',
                ],
                'ssl'  => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ];
            $context = stream_context_create($opts);

            $lastError = null;
            set_error_handler(static function (int $no, string $str) use (&$lastError): bool {
                $lastError = $str;
                return true;
            });
            $data = file_get_contents($url, false, $context);
            restore_error_handler();

            if ($data === false) {
                $this->logger->warning('[IntentProcessor] Image fetch failed', [
                    'url'   => $url,
                    'error' => $lastError ?? 'unknown',
                ]);
                return null;
            }

            $bytes = strlen($data);
            if ($bytes < 100) {
                $this->logger->warning('[IntentProcessor] Image fetch returned too little data', [
                    'url'   => $url,
                    'bytes' => $bytes,
                    'data'  => substr($data, 0, 200),
                ]);
                return null;
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->buffer($data) ?: 'image/jpeg';
            $this->logger->info('[IntentProcessor] Image fetched successfully', [
                'url'   => $url,
                'bytes' => $bytes,
                'mime'  => $mime,
            ]);
            return ['data' => base64_encode($data), 'mime' => $mime];
        } catch (\Throwable $e) {
            $this->logger->warning('[IntentProcessor] Image fetch exception', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fallbackResult(string $errorMsg): array
    {
        $text = 'Es tut mir leid, Ihre Anfrage konnte momentan nicht verarbeitet werden. '
              . 'Bitte versuchen Sie es erneut oder kontaktieren Sie uns direkt.';
        return [
            'intent'              => 'other',
            'confidence'          => 0.0,
            'tool_calls'          => [],
            'response_text'       => $text,
            'response_html'       => '<p>' . htmlspecialchars($text) . '</p>',
            'product_ids_to_show' => [],
            'extracted_items'     => [],
            'inline_images'       => [],
            '_error'              => $errorMsg,
        ];
    }

    /**
     * Keeps only well-formed order items ({sku?, name, qty}) and caps the list —
     * the LLM output is schema-guided but not guaranteed, and the result is
     * persisted to intent_data for the next conversation turn.
     *
     * @return array<int, array{sku: string, name: string, qty: float}>
     */
    private function sanitizeExtractedItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = trim((string)($item['name'] ?? ''));
            $qty  = $item['qty'] ?? null;
            if ($name === '' || !is_numeric($qty)) {
                continue;
            }
            $clean[] = [
                'sku'  => trim((string)($item['sku'] ?? '')),
                'name' => $name,
                'qty'  => (float)$qty,
            ];
            if (count($clean) >= self::MAX_EXTRACTED_ITEMS) {
                break;
            }
        }
        return $clean;
    }
}
