<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Zwernemann\Chat\Model\Llm\SystemPromptProvider;

/**
 * Renders the fixed base system prompt read-only in the admin config form.
 *
 * The base prompt is not editable — it is shown here purely so the operator can
 * see exactly what the model is instructed to do. Custom instructions go into the
 * separate "System Prompt Additions" field below it.
 */
class BasePromptInfo extends Field
{
    protected function _getElementHtml(AbstractElement $element): string
    {
        $prompt = SystemPromptProvider::BASE_PROMPT;

        $note = '<p style="margin:0 0 6px;color:#303030;">'
            . __('This is the built-in default system prompt. It is <strong>not editable</strong>. '
                . 'Enter your own instructions in the "System Prompt Additions" field below — they are '
                . 'automatically appended to the default prompt.')
            . '</p>';

        $textarea = '<textarea readonly="readonly" rows="14" '
            . 'style="width:100%;font-family:monospace;font-size:11px;line-height:1.4;'
            . 'background:#f6f6f6;color:#444;border:1px solid #ccc;border-radius:3px;padding:8px;" '
            . 'onclick="this.select()">'
            . $this->escapeHtml($prompt)
            . '</textarea>';

        return $note . $textarea;
    }
}
