<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class ConversationActions extends Column
{
    public function __construct(
        ContextInterface  $context,
        UiComponentFactory $factory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $factory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $actions = [
                'view' => [
                    'href'  => $this->urlBuilder->getUrl('zwernemann_chat/index/view', ['id' => $item['id']]),
                    'label' => __('View'),
                ],
            ];

            // Escalated is a premium-only state (the free NullEscalationHandler never
            // escalates). The approve action therefore targets the premium module's
            // route, which resolves only when ConversationalCommerce is installed.
            if (($item['status_code'] ?? $item['status'] ?? '') === 'escalated') {
                $actions['approve'] = [
                    'href'    => $this->urlBuilder->getUrl(
                        'conversationalcommerce/escalation/approve',
                        ['id' => $item['id']]
                    ),
                    'label'   => __('Approve'),
                    'confirm' => [
                        'title'   => __('Approve Conversation'),
                        'message' => __('Resume AI and continue conversation?'),
                    ],
                ];
            }

            $item[$this->getData('name')] = $actions;
        }
        return $dataSource;
    }
}
