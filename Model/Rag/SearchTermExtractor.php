<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Rag;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\LlmClientInterface;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Extracts product-relevant search terms from a free-form customer message
 * using a tiny Haiku call with forced tool_use output.
 *
 * Falls back to the original query as a single term only when the API call
 * fails. A successful empty result is authoritative: the message contains no
 * product terms and the keyword search is skipped.
 */
class SearchTermExtractor
{
    private const XML_CATALOG_LANG = 'zwernemann_chat/voyage/catalog_language';

    // Two clearly separated jobs, returned via two fields of the same single tool call:
    //   • "skus"  — exact identifiers copied verbatim (the keyword/exact catalog path).
    //   • "terms" — fuzzy semantic phrases for product names (the embedding/vector path).
    // Mixing them previously caused the model to "translate"/generalise away the literal
    // order codes; keeping them apart lets each job pull in its own direction.
    private const SYSTEM = 'You extract two separate things from a customer message and return them via the tool. '
        . 'FIRST — "skus": copy every article number, SKU, order code or model number EXACTLY as written '
        . 'into the "skus" array — verbatim, character for character. Never translate, abbreviate, normalize, '
        . 'reformat, deduplicate or omit any. Output one entry per line item, even when several lines share a '
        . 'similar code (e.g. both "ELK-0420" and "ELK-0442"). Return [] for "skus" if the message contains no codes. '
        . 'SECOND — "terms": short, specific semantic search phrases for the PRODUCT NAMES. For each term include '
        . 'both singular and plural forms (e.g. for "Tassen" add both "Tasse" and "Tassen", for "mugs" add both '
        . '"mug" and "mugs"). Do NOT put SKUs or order codes into "terms". Return [] for "terms" if no product '
        . 'names are mentioned.';

    private const TOOL_NAME   = 'return_search_terms';
    private const TOOL_SCHEMA = [
        'type'       => 'object',
        'properties' => [
            'skus' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Article numbers / SKUs / order codes copied verbatim from the message, '
                    . 'one entry per line item. [] if the message contains no codes.',
            ],
            'terms' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Semantic search terms for the product names (synonyms, singular/plural, '
                    . 'translations). Never contains SKUs or order codes. [] if no product names are mentioned.',
            ],
        ],
        'required' => ['skus', 'terms'],
    ];

    private bool $degraded = false;

    public function __construct(
        private readonly LlmClientInterface $llm,
        private readonly LoggerInterface    $logger,
        private readonly PipelineLogger     $pipelineLogger,
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * @param array<int, array{media_type: string, data: string}> $documentBlocks
     *   PDF attachments as native Anthropic document blocks. Haiku reads them
     *   directly and extracts search terms from their line items — no PHP-side
     *   PDF parsing anywhere in the pipeline.
     * @return array{terms: string[], skus: string[]}
     *   "skus" — exact identifiers copied verbatim from the message (fed ungefiltert into the
     *   keyword/exact catalog path); "terms" — fuzzy semantic phrases for the product names
     *   (fed into the embedding). On API failure the raw query is returned as a single term.
     *   An all-empty result means the message contains no product references.
     */
    public function extract(string $query, int $storeId = 0, array $documentBlocks = []): array
    {
        $this->degraded = false;

        $lang = trim((string)($this->scopeConfig->getValue(
            self::XML_CATALOG_LANG,
            $storeId > 0 ? ScopeInterface::SCOPE_STORE : ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $storeId > 0 ? $storeId : null
        ) ?? ''));

        $system = self::SYSTEM;
        if (!empty($documentBlocks)) {
            $system .= ' An order or quote document may be attached. For ALL of its line items, copy each'
                . ' article number / SKU verbatim into "skus" and the product-name search phrases into "terms"'
                . ' — one entry per line item. Ignore labor/service positions, prices, and totals.';
        }
        if ($lang !== '') {
            $system .= ' The product catalog is in ' . $lang . '.'
                . ' Include ' . $lang . ' synonyms or translations for any non-' . $lang . ' terms.';
        }

        // The Anthropic API rejects empty user turns (attachment-only emails).
        $userContent = trim($query) !== '' ? $query : '[Dokument im Anhang]';

        $this->pipelineLogger->section('SEARCH TERM EXTRACTION (Fast Model)');
        $this->pipelineLogger->raw('Input query', $userContent);
        $this->pipelineLogger->data('Document blocks', count($documentBlocks));
        $this->pipelineLogger->raw('System prompt', $system);

        $apiFailed = false;
        try {
            $raw = $this->llm->chatWithTool(
                [['role' => 'user', 'content' => $userContent]],
                $system,
                self::TOOL_NAME,
                self::TOOL_SCHEMA,
                // Use the active provider's fast model (Haiku / Ministral / Gemini Flash-Lite).
                // max_tokens must accommodate long parts lists (e.g. 16+ line items from a
                // PDF order), not just single-product queries — so override the fast default.
                array_merge($this->llm->getFastModelOptions(), ['max_tokens' => 1024]),
                $documentBlocks
            );

            $terms = array_values(array_filter(
                is_array($raw['terms'] ?? null) ? $raw['terms'] : [],
                'is_string'
            ));
            $skus = array_values(array_filter(
                is_array($raw['skus'] ?? null) ? $raw['skus'] : [],
                'is_string'
            ));

            // Strip common prefixes that customers write before SKU codes,
            // e.g. "SKU 08-0074" → "08-0074", "Art.Nr. 08-0074" → "08-0074"
            $stripPrefix = static function (string $t): string {
                return trim((string)preg_replace(
                    '/^\s*(?:sku|art(?:ikel)?(?:[\-\.]?nr\.?)?|ref(?:[\-\.]?nr\.?)?|bestellnummer|pos\.?)\s*/ui',
                    '',
                    trim($t)
                ));
            };
            $terms = array_values(array_filter(array_map($stripPrefix, $terms)));
            // Keep skus un-deduplicated: one entry per line item is the position count
            // that drives the dynamic topK downstream.
            $skus = array_values(array_filter(array_map($stripPrefix, $skus)));

        } catch (\Throwable $e) {
            // Provider-neutral overload detection: Anthropic "Overloaded", Mistral/Gemini
            // rate-limit / unavailable signals all mark the search as degraded.
            $msg = $e->getMessage();
            if (str_contains($msg, 'verload')
                || str_contains($msg, '429')
                || str_contains($msg, 'RESOURCE_EXHAUSTED')
                || str_contains($msg, 'UNAVAILABLE')
            ) {
                $this->degraded = true;
            }
            $this->logger->warning('[SearchTermExtractor] failed – ' . $e->getMessage());
            $terms     = [];
            $skus      = [];
            $apiFailed = true;
        }

        // Fallback to the raw query ONLY on API failure. A successful empty response is
        // authoritative: the message contains no product references — keyword search is
        // then skipped instead of being fed meaningless text.
        if (empty($terms) && empty($skus) && $apiFailed) {
            $terms = [mb_substr($query, 0, 200)];
        }

        $this->pipelineLogger->data('Extracted SKUs (verbatim)', $skus);
        $this->pipelineLogger->data('Extracted terms (after SKU prefix strip)', $terms);

        $this->logger->info('[SearchTermExtractor] extracted', [
            'query' => mb_substr($query, 0, 200),
            'skus'  => $skus,
            'terms' => $terms,
        ]);

        return ['terms' => $terms, 'skus' => $skus];
    }
}
