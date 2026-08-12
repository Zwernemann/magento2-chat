<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Test\Unit\Model\Llm;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use Zwernemann\Chat\Api\Data\UnifiedMessageInterface;
use Zwernemann\Chat\Model\Llm\ContextBuilder;
use Zwernemann\Chat\Model\Llm\MagentoToolRegistry;
use Zwernemann\Chat\Model\PipelineLogger;

/**
 * Covers the context sections that keep a pending clarification (e.g. an
 * order proposal extracted from a PDF) alive across turns, and the corrected
 * history/order handling for cart confirmations.
 */
class ContextBuilderTest extends TestCase
{
    private function createBuilder(): ContextBuilder
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn(null); // all defaults
        $pipelineLogger = $this->getMockBuilder(PipelineLogger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $toolRegistry = $this->getMockBuilder(MagentoToolRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $toolRegistry->method('buildToolCatalog')->willReturn('');

        return new ContextBuilder($config, $pipelineLogger, $toolRegistry);
    }

    /** @param array<string, mixed> $configMap */
    private function createBuilderWithConfig(array $configMap): ContextBuilder
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnCallback(
            fn(string $path) => $configMap[$path] ?? null
        );
        $pipelineLogger = $this->getMockBuilder(PipelineLogger::class)
            ->disableOriginalConstructor()
            ->getMock();
        $toolRegistry = $this->getMockBuilder(MagentoToolRegistry::class)
            ->disableOriginalConstructor()
            ->getMock();
        $toolRegistry->method('buildToolCatalog')->willReturn('');

        return new ContextBuilder($config, $pipelineLogger, $toolRegistry);
    }

    private function createMessage(string $contentText): UnifiedMessageInterface
    {
        $message = $this->createMock(UnifiedMessageInterface::class);
        $message->method('getContentText')->willReturn($contentText);
        $message->method('getResolvedEmail')->willReturn('kunde@example.com');
        $message->method('getCustomerIdentifier')->willReturn('kunde@example.com');
        $message->method('getAttachments')->willReturn([]);
        return $message;
    }

    public function testPendingClarificationSectionListsItems(): void
    {
        $builder = $this->createBuilder();

        $context = $builder->buildUserMessage(
            $this->createMessage('ja, bitte'),
            [],
            [],
            [],
            [],
            [],
            'Balkonkraftwerk',
            'cart',
            [
                'question' => 'Das Angebot enthält 16 Materialpositionen. Soll ich bestellen?',
                'items'    => [
                    ['sku' => 'ELK-0123', 'name' => 'Verteiler AP 4-reihig', 'qty' => 16],
                    ['sku' => '',         'name' => 'Kabelkanal 40x60',      'qty' => 2.5],
                ],
            ]
        );

        $this->assertStringContainsString('=== OFFENE RÜCKFRAGE (vorheriger Zug) ===', $context);
        $this->assertStringContainsString('16 Materialpositionen', $context);
        $this->assertStringContainsString('• 16x Verteiler AP 4-reihig (SKU: ELK-0123)', $context);
        $this->assertStringContainsString('• 2.5x Kabelkanal 40x60', $context);
        $this->assertStringContainsString('Wechsle NICHT zu einem', $context);
        // Section must come AFTER the resolved-search hint so it can override it.
        $this->assertGreaterThan(
            strpos($context, 'Aufgelöster Suchbegriff'),
            strpos($context, 'OFFENE RÜCKFRAGE')
        );
    }

    public function testNoPendingSectionWithoutQuestion(): void
    {
        $builder = $this->createBuilder();

        $context = $builder->buildUserMessage(
            $this->createMessage('Was habt ihr an Elektrosachen?'),
            [],
            [],
            []
        );

        $this->assertStringNotContainsString('OFFENE RÜCKFRAGE', $context);
    }

    public function testAttachmentOnlyMessageGetsExplicitNote(): void
    {
        $builder = $this->createBuilder();

        $context = $builder->buildUserMessage($this->createMessage(''), [], [], []);

        $this->assertStringContainsString('=== AKTUELLE ANFRAGE DES KUNDEN ===', $context);
        $this->assertStringContainsString('nur Dateianhänge gesendet', $context);
    }

    public function testOrderHistoryExpandedForReorderAndAccountOrder(): void
    {
        $builder = $this->createBuilder();
        $orders  = [[
            'increment_id' => '000000003',
            'created_at'   => '2026-06-09 16:14:27',
            'grand_total'  => 654.0,
            'status'       => 'pending',
            'items'        => [
                ['name' => 'Verbindungskabel', 'sku' => 'CAB-1', 'qty_ordered' => 2, 'price' => 12.5],
            ],
        ]];

        foreach (['reorder', 'account_order'] as $queryType) {
            $context = $builder->buildUserMessage(
                $this->createMessage('nochmal bitte'), [], $orders, [], [], [], '', $queryType
            );

            $this->assertStringContainsString('=== BESTELLVERLAUF (letzte 10 Bestellungen) ===', $context, $queryType);
            $this->assertStringContainsString('Bestellung #000000003', $context, $queryType);
            $this->assertStringContainsString('Verbindungskabel', $context, $queryType);
        }
    }

    public function testOrderHistoryOnlyReferencedForNonOrderIntents(): void
    {
        $builder = $this->createBuilder();
        $orders  = [[
            'increment_id' => '000000003',
            'created_at'   => '2026-06-09 16:14:27',
            'grand_total'  => 654.0,
            'status'       => 'pending',
            'items'        => [
                ['name' => 'Verbindungskabel', 'sku' => 'CAB-1', 'qty_ordered' => 2, 'price' => 12.5],
            ],
        ]];

        // A pure product search must NOT carry the full history — only a short note that it exists,
        // plus a hint that get_order_history can pull the details on demand.
        $product = $builder->buildUserMessage(
            $this->createMessage('ich suche verbindungskabel'), [], $orders, [], [], [], '', 'product'
        );
        $this->assertStringContainsString('Es existiert ein Bestellverlauf (1 Bestellung)', $product);
        $this->assertStringContainsString('get_order_history', $product);
        $this->assertStringNotContainsString('=== BESTELLVERLAUF (letzte 10 Bestellungen) ===', $product);
        $this->assertStringNotContainsString('Bestellung #000000003', $product);

        // Cart and address/account intents also only reference the history (no tool hint outside product).
        $cart = $builder->buildUserMessage(
            $this->createMessage('ja, bitte'), [], $orders, [], [], [], '', 'cart'
        );
        $this->assertStringContainsString('Es existiert ein Bestellverlauf', $cart);
        $this->assertStringNotContainsString('=== BESTELLVERLAUF (letzte 10 Bestellungen) ===', $cart);
    }

    public function testOrderHistoryLabelsDistinguishReorderEmptyFromNoOrders(): void
    {
        $builder = $this->createBuilder();

        // Order-related intent, but the customer has no orders at all.
        $reorderEmpty = $builder->buildUserMessage(
            $this->createMessage('bitte nochmal bestellen'), [], [], [], [], [], '', 'reorder'
        );
        $this->assertStringContainsString('Keine Bestellungen gefunden', $reorderEmpty);

        // Non-order intent with no orders → neutral placeholder, no reference note.
        $productEmpty = $builder->buildUserMessage(
            $this->createMessage('Zeig mir Produkte'), [], [], [], [], [], '', 'product'
        );
        $this->assertStringContainsString('Keine Bestellungen vorhanden', $productEmpty);
        $this->assertStringNotContainsString('Es existiert ein Bestellverlauf', $productEmpty);
    }

    public function testCartQueriesUseMainHistoryTurnCount(): void
    {
        $builder = $this->createBuilder();

        // 4 history messages; account turn count defaults to 2, main to 4.
        $history = [
            ['direction' => 'inbound',  'content_text' => 'Nachricht 1'],
            ['direction' => 'outbound', 'content_text' => 'Antwort 1'],
            ['direction' => 'inbound',  'content_text' => 'Nachricht 2'],
            ['direction' => 'outbound', 'content_text' => 'Antwort 2'],
        ];

        $cartMessages = $builder->buildMessages(
            $this->createMessage('ja, bitte'), [], [], [], $history, [], '', false, 'cart'
        );
        // 4 history turns + 1 current turn
        $this->assertCount(5, $cartMessages);
        $this->assertStringContainsString('Nachricht 1', $cartMessages[0]['content']);

        $accountMessages = $builder->buildMessages(
            $this->createMessage('Meine Adresse?'), [], [], [], $history, [], '', false, 'account_address'
        );
        // 2 history turns + 1 current turn
        $this->assertCount(3, $accountMessages);
    }

    public function testSystemPromptForbidsSkuModificationAndExplainsOptions(): void
    {
        $prompt = $this->createBuilder()->buildSystemPrompt();

        // SKUs must be used verbatim — no invented/suffixed variants like "ELK-0457-weiss".
        $this->assertStringContainsString('SKU-INTEGRITÄT', $prompt);
        $this->assertStringContainsString('NIEMALS erfinden', $prompt);
        $this->assertStringContainsString('ELK-0457-weiss', $prompt);

        // Configurable variants belong in the options field, not in the SKU string.
        $this->assertStringContainsString('KONFIGURIERBARE ARTIKEL', $prompt);
        $this->assertStringContainsString('"options"', $prompt);
    }

    public function testSystemPromptForbidsDirectCheckoutWhenPositionsUnresolved(): void
    {
        $prompt = $this->createBuilder()->buildSystemPrompt();

        // When not every position can be matched (or an option is missing), the model
        // must ask first instead of firing cart_add_item + cart_checkout — so its own
        // response (listing the not-found positions) is what gets sent.
        $this->assertStringContainsString('NICHT direkt bestellen', $prompt);
        $this->assertStringContainsString('WEDER cart_checkout NOCH cart_add_item', $prompt);
    }

    public function testSystemPromptHasFormalSalutationDirectiveByDefault(): void
    {
        // createBuilder() returns null for every config value → defaults apply.
        $prompt = $this->createBuilder()->buildSystemPrompt();

        $this->assertStringContainsString('=== ANSPRACHE & SIGNATUR (verbindlich) ===', $prompt);
        $this->assertStringContainsString('förmliche Anrede (Sie/Ihr)', $prompt);
        $this->assertStringContainsString('Mit freundlichen Grüßen', $prompt);
        // No shop name configured and no SMTP from_name → neutral fallback signature.
        $this->assertStringContainsString('Ihr Serviceteam', $prompt);
        // General greeting/farewell rule lives in the fixed base prompt.
        $this->assertStringContainsString('ANREDE & VERABSCHIEDUNG', $prompt);
    }

    public function testSystemPromptUsesConfiguredShopNameAndInformalForm(): void
    {
        $prompt = $this->createBuilderWithConfig([
            'zwernemann_chat/llm/address_form' => 'informal',
            'zwernemann_chat/llm/shop_name'    => 'Team Muster GmbH',
        ])->buildSystemPrompt();

        $this->assertStringContainsString('lockere Anrede (Du/Dein)', $prompt);
        $this->assertStringContainsString('Hallo <Vorname>', $prompt);
        $this->assertStringContainsString('Team Muster GmbH', $prompt);
        $this->assertStringNotContainsString('Mit freundlichen Grüßen', $prompt);
    }

    public function testOperatorAdditionsAreAppendedToBasePrompt(): void
    {
        $prompt = $this->createBuilderWithConfig([
            'zwernemann_chat/llm/system_prompt_additions' => 'Erwähne immer unsere kostenlose Hotline 0800-123.',
        ])->buildSystemPrompt();

        $this->assertStringContainsString('=== ZUSÄTZLICHE ANWEISUNGEN DES SHOP-BETREIBERS ===', $prompt);
        $this->assertStringContainsString('kostenlose Hotline 0800-123', $prompt);
        // Base prompt is still present (additions are appended, not replacing it).
        $this->assertStringContainsString('B2B-Einkaufsassistent', $prompt);
    }

    public function testPendingItemsExposeConfigurableOptionSchema(): void
    {
        $builder = $this->createBuilder();

        $context = $builder->buildUserMessage(
            $this->createMessage('ja, alle in weiß'),
            [],
            [],
            [],
            [],
            [],
            '',
            'cart',
            [
                'question' => 'Soll ich die Positionen bestellen?',
                'items'    => [
                    // Configurable position not in this turn's RAG window — its option
                    // schema must still reach the model so it can pass options:{Farbe}.
                    ['sku' => 'ELK-0442', 'name' => 'Doppelwechselschaltereinsatz 10A', 'qty' => 10,
                     'options_text' => 'Farbe: Weiß, Silber, Grau, Anthrazit'],
                    // Simple position — no schema, rendered without the configurable hint.
                    ['sku' => 'ELK-0545', 'name' => 'Danlux UP-Verteiler', 'qty' => 1, 'options_text' => ''],
                ],
            ]
        );

        $this->assertStringContainsString(
            '• 10x Doppelwechselschaltereinsatz 10A (SKU: ELK-0442) [Konfigurierbar — Farbe: Weiß, Silber, Grau, Anthrazit',
            $context
        );
        // Simple item keeps the plain rendering (no configurable hint).
        $this->assertStringContainsString('• 1x Danlux UP-Verteiler (SKU: ELK-0545)', $context);
        $this->assertStringNotContainsString('ELK-0545) [Konfigurierbar', $context);
    }
}
