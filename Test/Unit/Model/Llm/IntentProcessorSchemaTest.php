<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Llm;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Llm\IntentProcessor;

/**
 * Guards the submit_response tool schema contract: extracted_items is an
 * OPTIONAL addition — the original six required keys must stay unchanged so
 * all LLM providers keep producing valid responses.
 */
class IntentProcessorSchemaTest extends TestCase
{
    private function getToolSchema(): array
    {
        $reflection = new \ReflectionClassConstant(IntentProcessor::class, 'TOOL_SCHEMA');
        return $reflection->getValue();
    }

    private function buildToolSchema(bool $needsPlainText): array
    {
        $method = new \ReflectionMethod(IntentProcessor::class, 'toolSchema');
        $method->setAccessible(true);
        return $method->invoke(null, $needsPlainText);
    }

    public function testRequiredKeysUnchanged(): void
    {
        $schema = $this->getToolSchema();

        $this->assertSame(
            ['intent', 'confidence', 'tool_calls', 'response_text', 'response_html', 'product_ids_to_show'],
            $schema['required']
        );
    }

    public function testExtractedItemsIsOptionalArrayOfItems(): void
    {
        $schema = $this->getToolSchema();

        $this->assertArrayHasKey('extracted_items', $schema['properties']);
        $items = $schema['properties']['extracted_items'];
        $this->assertSame('array', $items['type']);
        $this->assertSame(['name', 'qty'], $items['items']['required']);
        $this->assertArrayHasKey('sku', $items['items']['properties']);
        $this->assertArrayHasKey('name', $items['items']['properties']);
        $this->assertArrayHasKey('qty', $items['items']['properties']);
        $this->assertNotContains('extracted_items', $schema['required']);
    }

    public function testToolSchemaWithPlainTextEqualsFullSchema(): void
    {
        // E-mail (needsPlainText=true) must keep the full contract, response_text included.
        $this->assertSame($this->getToolSchema(), $this->buildToolSchema(true));
    }

    public function testToolSchemaWithoutPlainTextDropsResponseText(): void
    {
        // WebChat (needsPlainText=false) omits response_text from properties AND required,
        // while every other required key stays intact.
        $schema = $this->buildToolSchema(false);

        $this->assertArrayNotHasKey('response_text', $schema['properties']);
        $this->assertNotContains('response_text', $schema['required']);
        $this->assertSame(
            ['intent', 'confidence', 'tool_calls', 'response_html', 'product_ids_to_show'],
            $schema['required']
        );
        $this->assertArrayHasKey('response_html', $schema['properties']);
    }
}
