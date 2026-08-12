<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PoNumberMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'none',          'label' => __('None (no PO number required)')],
            ['value' => 'ask_customer',  'label' => __('Ask customer for PO number via clarification email')],
            ['value' => 'auto_generate', 'label' => __('Auto-generate reference number (CC-YYYYMMDDHHIISS-CustomerID)')],
        ];
    }
}
