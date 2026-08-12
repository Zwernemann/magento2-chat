<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Block\Adminhtml\Dashboard;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Zwernemann\Chat\Model\Analytics\StatsService;

class Stats extends Template
{
    protected $_template = 'Zwernemann_Chat::dashboard/index.phtml';

    public function __construct(
        Context $context,
        private readonly StatsService $statsService,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getStats(): array
    {
        $days = max(1, (int)($this->getRequest()->getParam('days', 30)));
        return $this->statsService->getDashboardStats($days);
    }

    public function getSelectedDays(): int
    {
        return max(1, (int)($this->getRequest()->getParam('days', 30)));
    }

    public function getDashboardUrl(int $days): string
    {
        return $this->getUrl('*/*/index', ['days' => $days]);
    }

    public function formatCost(float $usd): string
    {
        return '$' . number_format($usd, 4);
    }

    public function formatRate(float $rate): string
    {
        return number_format($rate, 1) . ' %';
    }
}
