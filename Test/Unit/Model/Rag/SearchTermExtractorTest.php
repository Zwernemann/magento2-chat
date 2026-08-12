<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Rag;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\LlmClientInterface;
use Zwernemann\Chat\Model\PipelineLogger;
use Zwernemann\Chat\Model\Rag\SearchTermExtractor;

/**
 * The raw-query fallback may only kick in on API failure. A successful LLM
 * response of [] means "no product terms" and must be passed through, so the
 * keyword search is skipped instead of being fed meaningless text.
 */
class SearchTermExtractorTest extends TestCase
{
    private LlmClientInterface $llm;

    protected function setUp(): void
    {
        $this->llm = $this->createMock(LlmClientInterface::class);
        // The extractor reads the active provider's fast model via getFastModelOptions().
        $this->llm->method('getFastModelOptions')
            ->willReturn(['model' => 'gemini-2.5-flash-lite', 'max_tokens' => 100]);
    }

    private function createExtractor(): SearchTermExtractor
    {
        $pipelineLogger = $this->getMockBuilder(PipelineLogger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        return new SearchTermExtractor(
            $this->llm,
            $this->createMock(LoggerInterface::class),
            $pipelineLogger,
            $scopeConfig
        );
    }

    public function testSuccessfulEmptyResultIsRespected(): void
    {
        $this->llm->method('chatWithTool')->willReturn(['terms' => [], 'skus' => []]);

        $result = $this->createExtractor()->extract('%PDF-1.4 ReportLab Generated PDF document');

        $this->assertSame(['terms' => [], 'skus' => []], $result);
    }

    public function testApiFailureFallsBackToTruncatedQuery(): void
    {
        $this->llm->method('chatWithTool')
            ->willThrowException(new \RuntimeException('Anthropic API error'));

        $query  = str_repeat('a', 300);
        $result = $this->createExtractor()->extract($query);

        $this->assertSame(['terms' => [mb_substr($query, 0, 200)], 'skus' => []], $result);
    }

    public function testSuccessfulTermsAreReturnedWithSkuPrefixStripped(): void
    {
        $this->llm->method('chatWithTool')
            ->willReturn(['terms' => ['SKU 08-0279', 'Bobby Backpack'], 'skus' => []]);

        $result = $this->createExtractor()->extract('Bobby Backpack SKU 08-0279');

        $this->assertSame(['08-0279', 'Bobby Backpack'], $result['terms']);
        $this->assertSame([], $result['skus']);
    }

    public function testSkusAreReturnedVerbatimInTheirOwnField(): void
    {
        // Mirrors the logged 8-position quote: the LLM must return every code, one per line.
        $this->llm->method('chatWithTool')->willReturn([
            'skus'  => ['ELK-0425', 'ELK-0420', 'ELK-0442', 'ELK-0455', 'ELK-0405'],
            'terms' => ['Jung SCHUKO socket', 'thermostat'],
        ]);

        $result = $this->createExtractor()->extract('Was würde das kosten? …');

        $this->assertSame(['ELK-0425', 'ELK-0420', 'ELK-0442', 'ELK-0455', 'ELK-0405'], $result['skus']);
        $this->assertSame(['Jung SCHUKO socket', 'thermostat'], $result['terms']);
    }

    public function testSkuPrefixIsStrippedFromSkuField(): void
    {
        $this->llm->method('chatWithTool')
            ->willReturn(['skus' => ['Art.Nr. 08-0074', '0425'], 'terms' => []]);

        $result = $this->createExtractor()->extract('…');

        // Numeric-only codes survive too — no looksLikeIdentifier format gate here.
        $this->assertSame(['08-0074', '0425'], $result['skus']);
    }

    public function testDocumentBlocksAreForwardedToTheLlm(): void
    {
        $captured = [];
        $this->llm->method('chatWithTool')->willReturnCallback(
            function ($messages, $system, $tool, $schema, $options, $documentBlocks = []) use (&$captured) {
                $captured = [
                    'messages'       => $messages,
                    'system'         => $system,
                    'documentBlocks' => $documentBlocks,
                ];
                return ['terms' => ['Jung SCHUKO-Steckdose', 'Gira Raumtemperaturregler'], 'skus' => []];
            }
        );

        $blocks = [['media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 …')]];
        $result = $this->createExtractor()->extract('ich möchte die angehängten Artikel bestellen.', 0, $blocks);

        $this->assertSame($blocks, $captured['documentBlocks']);
        $this->assertStringContainsString('line items', $captured['system']);
        $this->assertSame(['Jung SCHUKO-Steckdose', 'Gira Raumtemperaturregler'], $result['terms']);
    }

    public function testEmptyQueryWithDocumentsUsesPlaceholderUserTurn(): void
    {
        $captured = [];
        $this->llm->method('chatWithTool')->willReturnCallback(
            function ($messages) use (&$captured) {
                $captured = $messages;
                return ['terms' => []];
            }
        );

        $blocks = [['media_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.4 …')]];
        $this->createExtractor()->extract('', 0, $blocks);

        $this->assertNotSame('', trim($captured[0]['content']));
    }

    public function testNoDocumentInstructionWithoutBlocks(): void
    {
        $captured = '';
        $this->llm->method('chatWithTool')->willReturnCallback(
            function ($messages, $system) use (&$captured) {
                $captured = $system;
                return ['terms' => []];
            }
        );

        $this->createExtractor()->extract('Balkonkraftwerk');

        $this->assertStringNotContainsString('line items', $captured);
    }
}
