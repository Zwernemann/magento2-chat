<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Rag;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Rag\ProductIndexer;

/**
 * Guards the hybrid-search result ordering. Keyword-only hits are exact identifier
 * matches (SKU / model number) and must never be evicted by semantic hits — otherwise
 * a line item from an attached order whose identifier matches but whose semantic score
 * ranks outside the topK is silently dropped and reported as "not found".
 */
class ProductIndexerOrderingTest extends TestCase
{
    /**
     * Regression: 30 semantic hits filling the topK budget previously pushed the
     * keyword-only exact match out of the result set. It must now be retained.
     */
    public function testKeywordOnlyExactMatchSurvivesWhenSemanticFillsTopK(): void
    {
        $merged = [];
        for ($i = 0; $i < 30; $i++) {
            $merged["VEC-$i"] = [
                'score'    => 0.9 - $i * 0.01,
                'source'   => $i < 4 ? 'hybrid' : 'vector',
                'metadata' => ['sku' => "VEC-$i"],
            ];
        }
        $merged['ELK-0583'] = [
            'score'    => 1.5,
            'source'   => 'keyword',
            'metadata' => ['sku' => 'ELK-0583'],
        ];

        $ordered = ProductIndexer::orderMergedHits($merged);
        $skus    = array_map(static fn($r) => $r['metadata']['sku'], $ordered);

        $this->assertContains('ELK-0583', $skus, 'keyword-only exact match must be retained');
        $this->assertCount(31, $ordered, 'every semantic and keyword-only hit is kept');
    }

    public function testSemanticHitsRankBeforeKeywordOnlyHits(): void
    {
        $merged = [
            'KW-1'  => ['score' => 1.5, 'source' => 'keyword', 'metadata' => ['sku' => 'KW-1']],
            'VEC-1' => ['score' => 0.4, 'source' => 'vector',  'metadata' => ['sku' => 'VEC-1']],
            'HYB-1' => ['score' => 0.8, 'source' => 'hybrid',  'metadata' => ['sku' => 'HYB-1']],
        ];

        $ordered = array_map(
            static fn($r) => $r['metadata']['sku'],
            ProductIndexer::orderMergedHits($merged)
        );

        // Semantic group (hybrid + vector) first, by score desc; keyword-only last.
        $this->assertSame(['HYB-1', 'VEC-1', 'KW-1'], $ordered);
    }

    public function testEachGroupSortedByScoreDescending(): void
    {
        $merged = [
            'VEC-LOW'  => ['score' => 0.30, 'source' => 'vector',  'metadata' => ['sku' => 'VEC-LOW']],
            'VEC-HIGH' => ['score' => 0.70, 'source' => 'vector',  'metadata' => ['sku' => 'VEC-HIGH']],
            'KW-LOW'   => ['score' => 1.50, 'source' => 'keyword', 'metadata' => ['sku' => 'KW-LOW']],
            'KW-HIGH'  => ['score' => 1.50, 'source' => 'keyword', 'metadata' => ['sku' => 'KW-HIGH']],
        ];

        $ordered = array_map(
            static fn($r) => $r['metadata']['sku'],
            ProductIndexer::orderMergedHits($merged)
        );

        $this->assertSame('VEC-HIGH', $ordered[0]);
        $this->assertSame('VEC-LOW', $ordered[1]);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $this->assertSame([], ProductIndexer::orderMergedHits([]));
    }
}
