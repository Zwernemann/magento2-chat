<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Pipeline;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Pipeline\OrderConfirmationFormatter;

/**
 * Unit tests for OrderConfirmationFormatter — the placeholder-vs-fallback
 * decision that keeps the LLM's localized order confirmation while injecting
 * the real Magento order number.
 */
class OrderConfirmationFormatterTest extends TestCase
{
    private const PH = OrderConfirmationFormatter::ORDER_NUMBER_PLACEHOLDER;

    public function testKeepsLlmTextAndSubstitutesPlaceholder(): void
    {
        $llmText = "Bonjour,\n\nVotre commande #" . self::PH . " a bien été enregistrée.\n"
            . "Vous recevrez sous peu un e-mail de confirmation.\n\nCordialement";
        $llmHtml = '<p>Votre commande <strong>#' . self::PH . '</strong> a bien été enregistrée.</p>';

        $result = OrderConfirmationFormatter::inject(
            $llmText,
            $llmHtml,
            '000000025',
            'FALLBACK TEXT',
            '<p>FALLBACK HTML</p>'
        );

        $this->assertTrue($result['kept_llm']);
        $this->assertStringContainsString('#000000025', $result['text']);
        $this->assertStringContainsString('Vous recevrez sous peu', $result['text']);
        $this->assertStringNotContainsString(self::PH, $result['text']);
        $this->assertStringContainsString('#000000025', $result['html']);
        $this->assertStringNotContainsString(self::PH, $result['html']);
    }

    public function testFallsBackWhenPlaceholderMissing(): void
    {
        $result = OrderConfirmationFormatter::inject(
            'LLM text without token',
            '<p>LLM html without token</p>',
            '000000025',
            'FALLBACK TEXT',
            '<p>FALLBACK HTML</p>'
        );

        $this->assertFalse($result['kept_llm']);
        $this->assertSame('FALLBACK TEXT', $result['text']);
        $this->assertSame('<p>FALLBACK HTML</p>', $result['html']);
    }

    public function testFallsBackWhenOrderRefEmpty(): void
    {
        $result = OrderConfirmationFormatter::inject(
            'Order #' . self::PH . ' placed',
            '<p>Order #' . self::PH . '</p>',
            '',
            'FALLBACK TEXT',
            '<p>FALLBACK HTML</p>'
        );

        $this->assertFalse($result['kept_llm']);
        $this->assertSame('FALLBACK TEXT', $result['text']);
    }

    public function testEscapesOrderRefInHtmlButNotInPlainText(): void
    {
        $result = OrderConfirmationFormatter::inject(
            'Order #' . self::PH,
            '<p>Order #' . self::PH . '</p>',
            'A&B<1>',
            'FALLBACK TEXT',
            '<p>FALLBACK HTML</p>'
        );

        $this->assertSame('Order #A&B<1>', $result['text']);
        $this->assertSame('<p>Order #A&amp;B&lt;1&gt;</p>', $result['html']);
    }

    public function testSubstitutesWhenPlaceholderOnlyInHtml(): void
    {
        $result = OrderConfirmationFormatter::inject(
            'Plain text without token',
            '<p>Order #' . self::PH . '</p>',
            '000000025',
            'FALLBACK TEXT',
            '<p>FALLBACK HTML</p>'
        );

        $this->assertTrue($result['kept_llm']);
        // text side has no token — left untouched (no-op str_replace)
        $this->assertSame('Plain text without token', $result['text']);
        $this->assertSame('<p>Order #000000025</p>', $result['html']);
    }

    public function testHasPlaceholder(): void
    {
        $this->assertTrue(OrderConfirmationFormatter::hasPlaceholder('x ' . self::PH . ' y'));
        $this->assertFalse(OrderConfirmationFormatter::hasPlaceholder('no token here'));
    }

    public function testStripPlaceholder(): void
    {
        $this->assertSame(
            'Bestellnummer: .',
            OrderConfirmationFormatter::stripPlaceholder('Bestellnummer: ' . self::PH . '.')
        );
        $this->assertSame('unchanged', OrderConfirmationFormatter::stripPlaceholder('unchanged'));
    }
}
