<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ConversationStatus implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'open',      'label' => __('Open')],
            ['value' => 'pending',   'label' => __('Pending')],
            ['value' => 'escalated', 'label' => __('Escalated')],
            ['value' => 'resolved',  'label' => __('Completed')],
        ];
    }
}
