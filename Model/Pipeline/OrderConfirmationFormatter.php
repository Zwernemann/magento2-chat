<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Pipeline;

/**
 * Merges the real Magento order number into the LLM's own order-confirmation reply.
 *
 * When a customer orders, the LLM composes a rich, multilingual confirmation
 * (greeting, item table, notes, signature — all in the customer's language) BEFORE
 * the order is actually placed, so it cannot know the order number yet. The prompt
 * therefore instructs the model to write the literal {@see ORDER_NUMBER_PLACEHOLDER}
 * token where the number belongs. After a successful checkout this formatter replaces
 * that token with the real increment id and keeps the LLM text intact.
 *
 * If the placeholder is missing (model did not comply) the caller's deterministic
 * fallback text is returned instead, so the order number is always delivered.
 *
 * Dependency-free by design: portable handlers (e.g. CartActionHandler) reuse it
 * without pulling in heavy Magento classes.
 */
class OrderConfirmationFormatter
{
    /** Token the LLM emits in its reply where the real Magento order number belongs. */
    public const ORDER_NUMBER_PLACEHOLDER = '{{ORDER_NUMBER}}';

    public static function hasPlaceholder(string $text): bool
    {
        return str_contains($text, self::ORDER_NUMBER_PLACEHOLDER);
    }

    /**
     * Remove any leftover placeholder token. Safety net for the rare case where the
     * LLM emitted the token but no successful checkout substituted it (e.g. the model
     * used the placeholder without actually calling cart_checkout).
     */
    public static function stripPlaceholder(string $text): string
    {
        return str_replace(self::ORDER_NUMBER_PLACEHOLDER, '', $text);
    }

    /**
     * Decide between keeping the LLM reply (placeholder substituted) and the
     * deterministic fallback.
     *
     * @return array{text: string, html: string, kept_llm: bool}
     */
    public static function inject(
        string $llmText,
        string $llmHtml,
        string $orderRef,
        string $fallbackText,
        string $fallbackHtml
    ): array {
        if ($orderRef !== '' && (self::hasPlaceholder($llmText) || self::hasPlaceholder($llmHtml))) {
            return [
                'text'     => str_replace(self::ORDER_NUMBER_PLACEHOLDER, $orderRef, $llmText),
                'html'     => str_replace(self::ORDER_NUMBER_PLACEHOLDER, htmlspecialchars($orderRef), $llmHtml),
                'kept_llm' => true,
            ];
        }

        return [
            'text'     => $fallbackText,
            'html'     => $fallbackHtml,
            'kept_llm' => false,
        ];
    }
}
