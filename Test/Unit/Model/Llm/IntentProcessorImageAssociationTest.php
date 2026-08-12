<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Llm;

use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Model\Llm\IntentProcessor;

/**
 * Covers associateProductImages(): product images must sit next to the product they
 * belong to (inline in the table row), unreferenced RAG hits must not dump a wall of
 * detached image cards at the end of the mail, and images the LLM already placed inline
 * must be left untouched.
 */
class IntentProcessorImageAssociationTest extends TestCase
{
    public function testThumbnailInjectedIntoMatchingSkuCell(): void
    {
        $html  = '<table><tr><td>1</td><td>Jung SCHUKO</td><td>ELK-0425</td><td>24</td></tr></table>';
        $cards = [
            ['cid' => 'product_2043', 'sku' => 'ELK-0425', 'name' => 'Jung SCHUKO', 'price' => 11.89],
        ];

        $out = IntentProcessor::associateProductImages($html, $cards);

        $this->assertStringContainsString(
            '<td><img src="cid:product_2043" alt="Jung SCHUKO" width="60"',
            $out,
            'thumbnail is injected into the SKU cell, next to its product'
        );
        $this->assertMatchesRegularExpression(
            '/<img src="cid:product_2043"[^>]*style="[^"]*height:auto[^"]*"/',
            $out,
            'thumbnail style must carry inline height:auto so the aspect ratio survives '
            . 'forwarding (where the <head> <style> block is stripped) and mobile clients'
        );
        $this->assertStringNotContainsString(
            '<div style="margin:10px',
            $out,
            'no detached card is appended when the image goes inline'
        );
    }

    public function testUnreferencedProductIsSkipped(): void
    {
        $html  = '<table><tr><td>ELK-0425</td></tr></table>';
        $cards = [
            ['cid' => 'product_2043', 'sku' => 'ELK-0425', 'name' => 'Shown',     'price' => 1.0],
            ['cid' => 'product_9999', 'sku' => 'ELK-9999', 'name' => 'Unrelated', 'price' => 5.0],
        ];

        $out = IntentProcessor::associateProductImages($html, $cards);

        $this->assertStringContainsString('cid:product_2043', $out);
        $this->assertStringNotContainsString(
            'cid:product_9999',
            $out,
            'a product the response never references must not produce an image'
        );
    }

    public function testAlreadyInlineImageLeftUntouched(): void
    {
        $html  = '<p>Foo <img src="cid:product_2043"> Bar</p>';
        $cards = [
            ['cid' => 'product_2043', 'sku' => 'ELK-0425', 'name' => 'X', 'price' => 1.0],
        ];

        $this->assertSame(
            $html,
            IntentProcessor::associateProductImages($html, $cards),
            'an image already placed inline by the LLM is not duplicated'
        );
    }

    public function testProseReferencedProductsAppendedInAppearanceOrder(): void
    {
        // Neither SKU sits in a <td> cell, so both fall back to appended cards —
        // ordered by where they first appear in the text, not by card order.
        $html  = '<p>First ELK-0002 then ELK-0001.</p>';
        $cards = [
            ['cid' => 'product_1', 'sku' => 'ELK-0001', 'name' => 'One', 'price' => 1.0],
            ['cid' => 'product_2', 'sku' => 'ELK-0002', 'name' => 'Two', 'price' => 2.0],
        ];

        $out = IntentProcessor::associateProductImages($html, $cards);

        $posTwo = strpos($out, 'cid:product_2');
        $posOne = strpos($out, 'cid:product_1');
        $this->assertNotFalse($posOne);
        $this->assertNotFalse($posTwo);
        $this->assertLessThan(
            $posOne,
            $posTwo,
            'ELK-0002 appears before ELK-0001 in the text, so its card comes first'
        );
    }

    public function testNoCardsLeavesHtmlUnchanged(): void
    {
        $html = '<p>Nothing to show.</p>';
        $this->assertSame($html, IntentProcessor::associateProductImages($html, []));
    }

    public function testFixedPixelHeightIsOverriddenToAuto(): void
    {
        // Regression: the LLM emitted an inline image with a fixed style height and no
        // width. Forcing width="200" on top of height:48px stretches the photo into a
        // 200×48 sliver. The capped width must come with height:auto, not a fixed height.
        $html = '<p><img src="cid:product_2043" '
            . 'style="vertical-align:middle;height:48px;margin-right:8px;"> Jung SCHUKO</p>';

        $out = IntentProcessor::normalizeProductImageSizing($html);

        $this->assertStringContainsString('width="200"', $out, 'width is capped to 200');
        $this->assertStringContainsString('height:auto', $out, 'fixed height becomes height:auto');
        $this->assertStringNotContainsString(
            'height:48px',
            $out,
            'the fixed pixel height must be overridden, not left to squash the image'
        );
        $this->assertStringContainsString('max-width:200px', $out, 'max-width cap is added');
        // Unrelated style declarations survive.
        $this->assertStringContainsString('vertical-align:middle', $out);
        $this->assertStringContainsString('margin-right:8px', $out);
    }

    public function testFixedHeightAttributeIsRemoved(): void
    {
        $html = '<img src="cid:product_1" width="200" height="48">';

        $out = IntentProcessor::normalizeProductImageSizing($html);

        $this->assertDoesNotMatchRegularExpression(
            '/\bheight\s*=/i',
            $out,
            'a fixed height attribute fights the width cap and must be dropped'
        );
        $this->assertStringContainsString('height:auto', $out);
    }

    public function testImageWithoutStyleGetsCapAndAutoHeight(): void
    {
        $html = '<img src="cid:product_7">';

        $out = IntentProcessor::normalizeProductImageSizing($html);

        $this->assertStringContainsString('width="200"', $out);
        $this->assertStringContainsString('max-width:200px;height:auto', $out);
    }

    public function testSixtyPxThumbnailIsLeftUntouched(): void
    {
        // The thumbnail injected into a SKU cell already carries width="60" + height:auto;
        // normalisation must not blow it up to 200px.
        $html = '<td><img src="cid:product_2043" alt="X" width="60" '
            . 'style="max-width:60px;height:auto;vertical-align:middle;margin-right:6px;"></td>';

        $out = IntentProcessor::normalizeProductImageSizing($html);

        $this->assertStringContainsString('width="60"', $out, 'existing width is preserved');
        $this->assertStringNotContainsString('width="200"', $out);
        $this->assertStringContainsString('max-width:60px', $out, 'existing max-width is preserved');
        $this->assertStringContainsString('height:auto', $out);
    }

    public function testMaxHeightCapIsStrippedToAuto(): void
    {
        // Regression: the LLM emitted style="…max-height:60px" with no width. The hard
        // width="200" cap plus a 60px height cap renders a 200×60 sliver — squashed via
        // max-height instead of a fixed height. Every height cap must give way to auto.
        $html = '<img src="cid:product_2551" alt="ELK-0529" '
            . 'style="display:block;max-width:200px;margin-bottom:4px;max-height:60px;">';

        $out = IntentProcessor::normalizeProductImageSizing($html);

        $this->assertStringNotContainsString(
            'max-height',
            $out,
            'a max-height cap fights the width cap and must be removed'
        );
        $this->assertStringContainsString('height:auto', $out, 'height is pinned to auto');
        $this->assertStringContainsString('width="200"', $out);
        // Unrelated declarations survive.
        $this->assertStringContainsString('display:block', $out);
        $this->assertStringContainsString('margin-bottom:4px', $out);
    }

    public function testMinHeightIsStrippedToAuto(): void
    {
        $html = '<img src="cid:product_5" style="min-height:80px;color:red;">';

        $out = IntentProcessor::normalizeProductImageSizing($html);

        $this->assertStringNotContainsString('min-height', $out, 'min-height must be removed too');
        $this->assertStringContainsString('color:red', $out, 'unrelated declarations survive');
        $this->assertStringContainsString('height:auto', $out);
    }

    public function testLineHeightIsPreserved(): void
    {
        $html = '<img src="cid:product_3" style="line-height:1.6;max-height:60px;">';

        $out = IntentProcessor::normalizeProductImageSizing($html);

        $this->assertStringContainsString('line-height:1.6', $out, 'line-height must not be mangled');
        $this->assertStringNotContainsString('max-height', $out);
        $this->assertStringContainsString('height:auto', $out);
    }
}
