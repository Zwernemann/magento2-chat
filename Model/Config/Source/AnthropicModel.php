<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class AnthropicModel implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'claude-sonnet-4-6', 'label' => __('Claude Sonnet 4.6 (fast, low cost)')],
            ['value' => 'claude-opus-4-7',   'label' => __('Claude Opus 4.7 (powerful, pricier)')],
            ['value' => 'claude-haiku-4-5-20251001', 'label' => __('Claude Haiku 4.5 (very fast, very low cost)')],
        ];
    }
}
