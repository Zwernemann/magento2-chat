<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Form of address used in the assistant's salutation/farewell.
 * Controlled outside the prompt — ContextBuilder turns the choice into a
 * concrete instruction injected into the system prompt at runtime.
 */
class AddressForm implements OptionSourceInterface
{
    public const FORMAL   = 'formal';
    public const INFORMAL = 'informal';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::FORMAL,   'label' => __('Formal (Sie)')],
            ['value' => self::INFORMAL, 'label' => __('Casual (Du)')],
        ];
    }
}
