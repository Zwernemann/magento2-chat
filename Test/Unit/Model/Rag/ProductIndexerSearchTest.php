<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Rag;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Model\Magento\CatalogSearch;
use Zwernemann\Chat\Model\Rag\PineconeClient;
use Zwernemann\Chat\Model\Rag\ProductIndexer;
use Zwernemann\Chat\Model\Rag\SearchTermExtractor;
use Zwernemann\Chat\Model\Rag\VoyageClient;

/**
 * Covers the hybrid search wiring added to fix the case where line items whose
 * semantic score ranks outside a snug topK were dropped:
 *   • LLM-identified SKUs are forwarded verbatim to the keyword/exact path.
 *   • The semantic budget (topK) scales with the number of line items, never
 *     dropping below the configured floor.
 */
class ProductIndexerSearchTest extends TestCase
{
    /** @var array{terms: string[], skus: string[]} */
    private array $extractorReturn;
    private array $capturedKeyword = [];
    private array $capturedPinecone = [];
    /** @var string[] Texts handed to VoyageClient::embedBatch on the last search */
    private array $capturedEmbedTexts = [];
    private int $pineconeCalls = 0;
    /** @var array<int, array<int, array<string, mixed>>> Queued per-call Pinecone responses */
    private array $pineconeQueue = [];

    private function buildIndexer(): ProductIndexer
    {
        $extractor = $this->createMock(SearchTermExtractor::class);
        $extractor->method('extract')->willReturnCallback(fn() => $this->extractorReturn);

        $catalogSearch = $this->createMock(CatalogSearch::class);
        $catalogSearch->method('search')->willReturnCallback(
            function (array $terms, int $limit = 10, int $storeId = 0, array $skus = []) {
                $this->capturedKeyword = ['terms' => $terms, 'limit' => $limit, 'skus' => $skus];
                return [];
            }
        );

        $voyage = $this->createMock(VoyageClient::class);
        $voyage->method('embedBatch')->willReturnCallback(
            function (array $texts) {
                $this->capturedEmbedTexts = $texts;
                // One vector per text, mirroring the real batch API.
                return array_map(static fn() => [0.1, 0.2, 0.3], $texts);
            }
        );

        $pinecone = $this->createMock(PineconeClient::class);
        $pinecone->method('query')->willReturnCallback(
            function (array $vector, ?int $topK = null, array $filter = [], string $namespace = '') {
                $this->pineconeCalls++;
                $this->capturedPinecone = ['topK' => $topK, 'namespace' => $namespace];
                return array_shift($this->pineconeQueue) ?? [];
            }
        );

        $store = $this->createMock(StoreInterface::class);
        $store->method('getCode')->willReturn('default');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new ProductIndexer(
            $this->createMock(CollectionFactory::class),
            $this->createMock(ProductRepositoryInterface::class),
            $voyage,
            $pinecone,
            $catalogSearch,
            $extractor,
            $storeManager,
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(ResourceConnection::class),
            $this->createMock(ConfigurableType::class),
            $this->createMock(EavConfig::class)
        );
    }

    public function testSkusAreForwardedVerbatimToKeywordSearch(): void
    {
        $skus = ['ELK-0425', 'ELK-0420', 'ELK-0442', 'ELK-0455', 'ELK-0405'];
        $this->extractorReturn = ['terms' => ['Jung SCHUKO socket'], 'skus' => $skus];

        $this->buildIndexer()->search('Was würde das kosten? …', 25, 1);

        $this->assertSame($skus, $this->capturedKeyword['skus']);
    }

    public function testTopKScalesWithLineItemCount(): void
    {
        // 8 line items, configured floor 10 → max(10, min(8*2+8, 50)) = 24.
        $this->extractorReturn = [
            'terms' => [],
            'skus'  => array_map(static fn($i) => "ELK-040$i", range(1, 8)),
        ];

        $this->buildIndexer()->search('eight positions', 10, 1);

        $this->assertSame(24, $this->capturedKeyword['limit']);
        $this->assertSame(24, $this->capturedPinecone['topK']);
    }

    public function testTopKNeverDropsBelowConfiguredFloor(): void
    {
        // No SKUs → min(8, 50) = 8, but the configured floor of 25 wins.
        $this->extractorReturn = ['terms' => ['thermostat'], 'skus' => []];

        $this->buildIndexer()->search('a single product question', 25, 1);

        $this->assertSame(25, $this->capturedPinecone['topK']);
    }

    public function testTopKIsCappedAtFifty(): void
    {
        // 25 line items → min(25*2+8, 50) = 50.
        $this->extractorReturn = [
            'terms' => [],
            'skus'  => array_map(static fn($i) => "SKU-$i", range(1, 25)),
        ];

        $this->buildIndexer()->search('a very large quote', 10, 1);

        $this->assertSame(50, $this->capturedPinecone['topK']);
    }

    public function testAttachedDocumentEmbedsEachLineItemSeparately(): void
    {
        // With an attached document, each extracted line-item term gets its OWN
        // embedding text + its OWN Pinecone query — instead of one diluted blob that
        // averages every position into a single vector and loses specific articles.
        $terms = [
            'Kreuzschalter', 'Rollladentaster', 'Raumtemperaturregler',
            'Bewegungsmelder', 'Überspannungsableiter', 'UP-Verteiler',
        ];
        $this->extractorReturn = ['terms' => $terms, 'skus' => []];
        $docBlocks = [['media_type' => 'application/pdf', 'data' => 'x']];

        $this->buildIndexer()->search('ich möchte die angehängten Artikel bestellen', 25, 1, $docBlocks);

        // One embedding text per line item (deduped, message blob excluded) …
        $this->assertSame($terms, $this->capturedEmbedTexts);
        // … and one Pinecone query per embedded vector.
        $this->assertSame(count($terms), $this->pineconeCalls);
    }

    public function testPlainChatStillUsesSingleEmbeddingAndQuery(): void
    {
        // No attachment → legacy behaviour: embed the message once, query Pinecone once.
        $this->extractorReturn = ['terms' => ['thermostat'], 'skus' => []];

        $this->buildIndexer()->search('Habt ihr Thermostate?', 25, 1);

        $this->assertSame(['Habt ihr Thermostate?'], $this->capturedEmbedTexts);
        $this->assertSame(1, $this->pineconeCalls);
    }

    public function testLowScoringLineItemHitIsNotStarvedByLoudItems(): void
    {
        // Regression: a per-item query whose exact match scores low must still reach the
        // context, even when other line items return many high-scoring matches. A global
        // score cap (topK = 8 here) would rank the low scorer out; per-item retention keeps it.
        $this->extractorReturn = [
            'terms' => ['loud item', 'mid item', 'weak exact item'],
            'skus'  => [],
        ];
        $this->pineconeQueue = [
            [
                ['score' => 0.90, 'metadata' => ['sku' => 'LOUD-1']],
                ['score' => 0.89, 'metadata' => ['sku' => 'LOUD-2']],
                ['score' => 0.88, 'metadata' => ['sku' => 'LOUD-3']],
                ['score' => 0.87, 'metadata' => ['sku' => 'LOUD-4']],
            ],
            [
                ['score' => 0.86, 'metadata' => ['sku' => 'MID-1']],
                ['score' => 0.85, 'metadata' => ['sku' => 'MID-2']],
                ['score' => 0.84, 'metadata' => ['sku' => 'MID-3']],
                ['score' => 0.83, 'metadata' => ['sku' => 'MID-4']],
            ],
            [['score' => 0.20, 'metadata' => ['sku' => 'WEAK-EXACT']]],
        ];
        $docBlocks = [['media_type' => 'application/pdf', 'data' => 'x']];

        // topK 8 → a global cap would drop the 9th-ranked WEAK-EXACT hit.
        $results = $this->buildIndexer()->search('attached items', 8, 1, $docBlocks);
        $skus = array_map(static fn($r) => $r['metadata']['sku'], $results);

        $this->assertContains('WEAK-EXACT', $skus, 'low-scoring per-item exact match must survive');
        $this->assertCount(9, $results, 'every per-item hit is retained (no global score cap)');
    }

    public function testPerItemMergeKeepsHighestScorePerSku(): void
    {
        // The same catalog SKU surfaces for two different line items with different
        // scores; the merge must keep the highest (closest match wins).
        $this->extractorReturn = ['terms' => ['Raumtemperaturregler', 'Thermostat'], 'skus' => []];
        $this->pineconeQueue = [
            [['score' => 0.42, 'metadata' => ['sku' => 'ELK-0394', 'name' => 'B&J Reflex']]],
            [['score' => 0.61, 'metadata' => ['sku' => 'ELK-0394', 'name' => 'B&J Reflex']]],
        ];
        $docBlocks = [['media_type' => 'application/pdf', 'data' => 'x']];

        $results = $this->buildIndexer()->search('attached items', 25, 1, $docBlocks);

        $this->assertCount(1, $results);
        $this->assertSame('ELK-0394', $results[0]['metadata']['sku']);
        $this->assertEqualsWithDelta(0.61, $results[0]['score'], 0.0001);
    }
}
