<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LlmProvider implements OptionSourceInterface
{
    /**
     * Providers available in the free Chat module. The premium
     * ConversationalCommerce module overrides this option source (and the config
     * field) to add Mistral AI and Google Gemini.
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'anthropic', 'label' => 'Anthropic Claude'],
        ];
    }
}
