<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Rag;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zwernemann\Chat\Api\LlmClientInterface;
use Zwernemann\Chat\Model\PipelineLogger;
use Zwernemann\Chat\Model\Rag\ConversationalQueryBuilder;

/**
 * Regression tests for the history loop: an empty inbound message (e.g. an
 * attachment-only email) must not cause the assistant reply that follows it
 * to be dropped by the role-alternation rule. That drop made the classifier
 * resolve short confirmations ("ja, bitte") against an older topic.
 */
class ConversationalQueryBuilderTest extends TestCase
{
    /** @var array<int, array{role: string, content: string}> */
    private array $capturedMessages = [];

    private function createBuilder(array $llmResult): ConversationalQueryBuilder
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getFastModelOptions')->willReturn([]);
        $llm->method('chatWithTool')->willReturnCallback(
            function (array $messages) use ($llmResult): array {
                $this->capturedMessages = $messages;
                return $llmResult;
            }
        );
        $pipelineLogger = $this->getMockBuilder(PipelineLogger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        return new ConversationalQueryBuilder(
            $llm,
            $this->createMock(LoggerInterface::class),
            $pipelineLogger,
            $scopeConfig
        );
    }

    public function testEmptyInboundDoesNotDropFollowingAssistantTurn(): void
    {
        $builder = $this->createBuilder(['query_type' => 'cart', 'search_query' => 'PDF Positionen']);

        // DESC order (newest first), as delivered by the message resource:
        // outbound clarification ← inbound '' (attachment-only) ← outbound order confirmation
        $history = [
            ['direction' => 'outbound', 'content_text' => 'Das Angebot enthält 16 Materialpositionen. Soll ich bestellen?'],
            ['direction' => 'inbound',  'content_text' => ''],
            ['direction' => 'outbound', 'content_text' => 'Ihre Bestellung #000000003 wurde erfolgreich aufgegeben.'],
        ];

        $builder->build('ja, bitte', $history);

        // Both assistant messages survive as ONE merged turn; the clarification
        // question must be visible to the classifier.
        $this->assertCount(2, $this->capturedMessages);
        $this->assertSame('assistant', $this->capturedMessages[0]['role']);
        $this->assertStringContainsString('Bestellung #000000003', $this->capturedMessages[0]['content']);
        $this->assertStringContainsString('16 Materialpositionen', $this->capturedMessages[0]['content']);
        $this->assertSame(['role' => 'user', 'content' => 'ja, bitte'], $this->capturedMessages[1]);
    }

    public function testEmptyCurrentMessageIsReplacedByPlaceholder(): void
    {
        $builder = $this->createBuilder(['query_type' => 'product', 'search_query' => '']);

        $builder->build('', []);

        $this->assertCount(1, $this->capturedMessages);
        $this->assertSame('user', $this->capturedMessages[0]['role']);
        $this->assertNotSame('', trim($this->capturedMessages[0]['content']));
    }

    public function testConsecutiveUserMessagesAreMerged(): void
    {
        $builder = $this->createBuilder(['query_type' => 'cart', 'search_query' => 'X']);

        // DESC order; chronologically: assistant list → two user messages in a
        // row (rapid replies) → assistant order confirmation.
        $history = [
            ['direction' => 'outbound', 'content_text' => 'Ihre Bestellung #000000003 wurde erfolgreich aufgegeben.'],
            ['direction' => 'inbound',  'content_text' => 'das mit der sku ELK-0359'],
            ['direction' => 'inbound',  'content_text' => 'ich bestelle ein Balkonkraftwerk'],
            ['direction' => 'outbound', 'content_text' => 'Hier ist unsere Übersicht.'],
        ];

        $builder->build('ja', $history);

        $this->assertCount(4, $this->capturedMessages);
        $this->assertSame('assistant', $this->capturedMessages[0]['role']);
        $this->assertSame('user', $this->capturedMessages[1]['role']);
        $this->assertStringContainsString('ich bestelle ein Balkonkraftwerk', $this->capturedMessages[1]['content']);
        $this->assertStringContainsString('ELK-0359', $this->capturedMessages[1]['content']);
        $this->assertSame('assistant', $this->capturedMessages[2]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'ja'], $this->capturedMessages[3]);
    }

    public function testContinuesTopicIsSurfacedFromClassifier(): void
    {
        $builder = $this->createBuilder([
            'query_type'      => 'product',
            'search_query'    => 'Kabeltrommel 50m',
            'continues_topic' => false,
        ]);

        $result = $builder->build('Ich benötige 2 Kabeltrommeln', [
            ['direction' => 'outbound', 'content_text' => 'Welche Farbe für die SCHUKO-Steckdosen?'],
            ['direction' => 'inbound',  'content_text' => '10 Schuko-Steckdosen'],
        ]);

        $this->assertFalse($result['continues_topic']);
    }

    public function testContinuesTopicDefaultsTrueWhenFieldAbsent(): void
    {
        // A clarification answer that the classifier returns without the field, or a
        // malformed response, must never split the conversation (and lose its context).
        $builder = $this->createBuilder(['query_type' => 'product', 'search_query' => 'Autolack']);

        $result = $builder->build('Bitte alles in weiß. Aber die Dinge aus dem Warenkorb nicht bestellen!', [
            ['direction' => 'outbound', 'content_text' => 'Welche Farbe für die 16 Positionen?'],
            ['direction' => 'inbound',  'content_text' => 'ich möchte die angehängten Artikel bestellen'],
        ]);

        $this->assertTrue($result['continues_topic']);
    }

    public function testContinuesTopicDefaultsTrueOnClassifierFailure(): void
    {
        $llm = $this->createMock(LlmClientInterface::class);
        $llm->method('getFastModelOptions')->willReturn([]);
        $llm->method('chatWithTool')->willThrowException(new \RuntimeException('boom'));
        $pipelineLogger = $this->getMockBuilder(PipelineLogger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $builder = new ConversationalQueryBuilder(
            $llm,
            $this->createMock(LoggerInterface::class),
            $pipelineLogger,
            $scopeConfig
        );

        $result = $builder->build('ja, bitte', [
            ['direction' => 'outbound', 'content_text' => 'Soll ich bestellen?'],
        ]);

        $this->assertTrue($result['continues_topic']);
    }
}
