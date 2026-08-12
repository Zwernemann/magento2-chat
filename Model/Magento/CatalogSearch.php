<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Magento;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Keyword-based product search using LIKE filters on name and SKU.
 * Receives pre-extracted search terms (from SearchTermExtractor) so it has
 * no language dependency. Works on every Magento setup.
 */
class CatalogSearch
{
    public function __construct(
        private readonly CollectionFactory     $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger,
        private readonly PipelineLogger        $pipelineLogger
    ) {}

    /**
     * @param  string[] $terms    Pre-extracted product terms (e.g. ["Prosonic", "brochures"])
     * @param  int      $storeId  Magento store ID to scope the search (0 = default store)
     * @param  string[] $skus     LLM-identified article numbers / SKUs, taken verbatim and matched
     *                            WITHOUT the looksLikeIdentifier format check — SKU formats vary per
     *                            shop/ERP, so identifier detection stays with the LLM, not this module.
     * @return array<int, array{score: float, source: string, metadata: array<string, mixed>}>
     */
    public function search(array $terms, int $limit = 10, int $storeId = 0, array $skus = []): array
    {
        if (empty($terms) && empty($skus)) {
            return [];
        }

        $this->pipelineLogger->section('CATALOG KEYWORD SEARCH (DB LIKE)');
        $this->pipelineLogger->data('Terms', $terms);
        $this->pipelineLogger->data('SKUs (verbatim from LLM)', $skus);

        try {
            $storeId = $storeId > 0
                ? $storeId
                : (int)$this->storeManager->getDefaultStoreView()->getId();

            $collection = $this->collectionFactory->create();
            $collection->setStore($storeId);
            $collection->addWebsiteFilter($this->storeManager->getStore($storeId)->getWebsiteId());
            $collection->addAttributeToSelect(['name', 'sku', 'short_description', 'price', 'image']);
            $collection->addAttributeToFilter('status', 1);
            $collection->addAttributeToFilter('visibility', ['neq' => 1]);

            // Lexical search adds value only for exact identifiers (SKU / model numbers) —
            // semantic search already covers brand/category words. Restricting to
            // identifier-like tokens prevents broad "name LIKE '%brand%'" floods that
            // would otherwise crowd out precise semantic hits in the merged result set.
            $idTerms = [];
            // LLM-identified SKUs are trusted verbatim — no format check (covers numeric-only
            // or ERP-specific codes that looksLikeIdentifier would reject).
            foreach ($skus as $sku) {
                $sku = trim((string)$sku);
                if ($sku !== '') {
                    $idTerms[$sku] = true;
                }
            }
            foreach ($terms as $term) {
                foreach (preg_split('/[\s,]+/', trim((string)$term)) as $tok) {
                    if ($this->looksLikeIdentifier($tok)) {
                        $idTerms[$tok] = true;
                    }
                }
            }
            if (empty($idTerms)) {
                $this->pipelineLogger->data('SQL filter conditions (OR)', []);
                return [];
            }

            // OR across all identifier terms × (name, sku)
            $conditions = [];
            foreach (array_keys($idTerms) as $term) {
                $escaped      = addcslashes($term, '%_\\');
                $conditions[] = ['attribute' => 'name', 'like' => '%' . $escaped . '%'];
                $conditions[] = ['attribute' => 'sku',  'like' => '%' . $escaped . '%'];
            }
            $this->pipelineLogger->data('SQL filter conditions (OR)', $conditions);
            $collection->addAttributeToFilter($conditions);
            $collection->setPageSize($limit);
            $collection->load();

            $mediaBase = rtrim(
                $this->storeManager->getStore($storeId)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA),
                '/'
            );

            $results = [];
            foreach ($collection as $product) {
                $imgPath  = $product->getImage();
                $imageUrl = ($imgPath && $imgPath !== 'no_selection')
                    ? $mediaBase . '/catalog/product' . $imgPath
                    : '';

                $results[] = [
                    'score'    => 1.0,
                    'source'   => 'keyword',
                    'metadata' => [
                        'product_id' => (int)$product->getId(),
                        'sku'        => (string)$product->getSku(),
                        'name'       => (string)$product->getName(),
                        'price'      => (float)$product->getFinalPrice(),
                        'image_url'  => $imageUrl,
                        'categories' => '',
                        'short_desc' => mb_substr(
                            strip_tags((string)$product->getShortDescription()), 0, 500
                        ),
                    ],
                ];
            }

            $this->pipelineLogger->data('Keyword search results (' . count($results) . ' hits)', $results);
            $this->logger->info('[Catalog] Keyword search', [
                'terms'   => $terms,
                'hits'    => count($results),
                'results' => array_map(
                    fn($r) => ['name' => $r['metadata']['name'], 'sku' => $r['metadata']['sku']],
                    $results
                ),
            ]);

            return $results;

        } catch (\Throwable $e) {
            $this->logger->warning('[Catalog] Keyword search failed – ' . $e->getMessage());
            return [];
        }
    }

    /**
     * True for a single token that looks like an exact identifier (SKU or model
     * number): contains a digit AND a letter and only [A-Za-z0-9-/._].
     * Examples: ELK-0560, AS5020U, PLSM-B32/3N-MW, Cat.7.
     * Rejects brand/category words (no digit) and bare numbers like "55"/"2000".
     */
    private function looksLikeIdentifier(string $t): bool
    {
        $t = trim($t);

        return $t !== ''
            && mb_strlen($t) >= 3
            && preg_match('/\d/', $t) === 1
            && preg_match('/[A-Za-z]/', $t) === 1
            && preg_match('#^[A-Za-z0-9][A-Za-z0-9\-/._]*$#', $t) === 1;
    }
}
