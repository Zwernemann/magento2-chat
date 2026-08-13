<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Provider options for the "Active Provider" dropdown.
 *
 * The list is data-driven and injected via di.xml, mirroring the LlmClientResolver
 * client pool. The free Chat module ships Anthropic only; the premium module merges
 * Mistral and Google Gemini into the same array, so the dropdown always matches the
 * providers that are actually wired.
 *
 * @see \Zwernemann\Chat\Model\Llm\LlmClientResolver
 */
class LlmProvider implements OptionSourceInterface
{
    /**
     * @param array<string, string> $providers provider key => label
     */
    public function __construct(
        private readonly array $providers = ['anthropic' => 'Anthropic Claude']
    ) {}

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->providers as $value => $label) {
            $options[] = ['value' => (string)$value, 'label' => __($label)];
        }
        return $options;
    }
}
