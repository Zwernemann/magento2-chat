<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Magento;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Model\Magento\CatalogSearch;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Covers the identifier heuristic that restricts the lexical (LIKE) search to
 * exact SKU / model-number tokens, so broad brand or category words no longer
 * flood the merged result set and crowd out precise semantic hits.
 */
class CatalogSearchTest extends TestCase
{
    private function invokeLooksLikeIdentifier(string $term): bool
    {
        $search = new CatalogSearch(
            $this->createMock(CollectionFactory::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(LoggerInterface::class),
            $this->getMockBuilder(PipelineLogger::class)->disableOriginalConstructor()->getMock()
        );

        $method = new \ReflectionMethod($search, 'looksLikeIdentifier');
        $method->setAccessible(true);

        return (bool)$method->invoke($search, $term);
    }

    /**
     * @dataProvider identifierProvider
     */
    public function testLooksLikeIdentifier(string $term, bool $expected): void
    {
        $this->assertSame($expected, $this->invokeLooksLikeIdentifier($term));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function identifierProvider(): array
    {
        return [
            // Exact identifiers — keyword search adds value here (vector is weak on codes).
            'SKU'                 => ['ELK-0560', true],
            'model number'        => ['AS5020U', true],
            'model with slash'    => ['PLSM-B32/3N-MW', true],
            'cat cable'           => ['Cat.7', true],
            // Brand / category words — semantic search handles these; must NOT match.
            'brand Merten'        => ['Merten', false],
            'brand Hager'         => ['Hager', false],
            'brand EcoFlow'       => ['EcoFlow', false],
            'phrase socket'       => ['SCHUKO socket', false],
            'phrase distribution' => ['distribution board', false],
            // Bare numbers and too-short tokens must not flood either.
            'bare number'         => ['2000', false],
            'short number'        => ['55', false],
            'too short'           => ['A1', false],
            'empty'               => ['', false],
        ];
    }
}
