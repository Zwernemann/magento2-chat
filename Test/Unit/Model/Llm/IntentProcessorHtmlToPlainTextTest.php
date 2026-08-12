<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Llm;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Llm\IntentProcessor;

/**
 * Covers htmlToPlainText(): the HTML→plaintext derivation used for HTML-only
 * channels (WebChat) whose schema omits response_text. The result feeds DB
 * history and the JS fallback, so it must be tag-free, readable and lossless
 * for order-number placeholders.
 */
class IntentProcessorHtmlToPlainTextTest extends TestCase
{
    public function testStripsAllTagsAndKeepsText(): void
    {
        $html = '<p>Guten Tag,</p><p>hier ist Ihr <strong>Angebot</strong>.</p>';
        $text = IntentProcessor::htmlToPlainText($html);

        $this->assertStringNotContainsString('<', $text);
        $this->assertStringContainsString('Guten Tag,', $text);
        $this->assertStringContainsString('Angebot', $text);
    }

    public function testTableRowsBecomeSeparateLines(): void
    {
        $html = '<table><thead><tr><th>Produkt</th><th>SKU</th></tr></thead>'
            . '<tbody><tr><td>Acryllack</td><td>LCK-0047</td></tr>'
            . '<tr><td>Klarlack</td><td>LCK-0079</td></tr></tbody></table>';
        $text = IntentProcessor::htmlToPlainText($html);

        // Each product ends up on its own line (rows do not run together).
        $lines = array_values(array_filter(explode("\n", $text), static fn($l) => trim($l) !== ''));
        $this->assertContains('Produkt SKU', $lines);
        $this->assertContains('Acryllack LCK-0047', $lines);
        $this->assertContains('Klarlack LCK-0079', $lines);
    }

    public function testImageTagsLeaveNoResidue(): void
    {
        $html = '<td><img src="cid:product_339" width="200"><strong>Acryllack</strong></td>';
        $text = IntentProcessor::htmlToPlainText($html);

        $this->assertStringNotContainsString('img', $text);
        $this->assertStringNotContainsString('cid:', $text);
        $this->assertStringContainsString('Acryllack', $text);
    }

    public function testOrderNumberPlaceholderPreserved(): void
    {
        $html = '<p>Ihre Bestellnummer lautet {{ORDER_NUMBER}}. Vielen Dank.</p>';
        $text = IntentProcessor::htmlToPlainText($html);

        $this->assertStringContainsString('{{ORDER_NUMBER}}', $text);
    }

    public function testDecodesEntitiesAndCollapsesWhitespace(): void
    {
        $html = "<p>Preis:&nbsp;19,83&nbsp;EUR</p>\n\n\n<p>Maler-&amp;-Industrielacke</p>";
        $text = IntentProcessor::htmlToPlainText($html);

        $this->assertStringContainsString('19,83 EUR', $text);
        $this->assertStringContainsString('Maler-&-Industrielacke', $text);
        $this->assertStringNotContainsString("\n\n\n", $text);
    }
}
