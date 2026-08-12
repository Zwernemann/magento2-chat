<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Renders the conversation status cell with a colour-coded badge.
 */
class ConversationStatusColumn extends Column
{
    private const STYLES = [
        'open'      => 'color:#155724;background:#d4edda;border:1px solid #c3e6cb',
        'pending'   => 'color:#856404;background:#fff3cd;border:1px solid #ffeeba',
        'escalated' => 'color:#721c24;background:#f8d7da;border:1px solid #f5c6cb',
        'resolved'  => 'color:#6c757d;background:#e2e3e5;border:1px solid #d6d8db',
    ];

    private const LABELS = [
        'open'      => 'Open',
        'pending'   => 'Pending',
        'escalated' => 'Eskaliert ⚠',
        'resolved'  => 'Completed',
    ];

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }
        foreach ($dataSource['data']['items'] as &$item) {
            $status = (string)($item['status'] ?? '');
            $style  = self::STYLES[$status] ?? 'color:#000';
            $label  = self::LABELS[$status] ?? $status;
            $item['status_code'] = $status;
            $item['status'] = sprintf(
                '<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:12px;font-weight:bold;%s">%s</span>',
                $style,
                htmlspecialchars($label)
            );
        }
        return $dataSource;
    }
}
