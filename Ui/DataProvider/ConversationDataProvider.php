<?php
declare(strict_types=1);

namespace Zwernemann\Chat\Ui\DataProvider;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Ui\DataProvider\AbstractDataProvider;
use Zwernemann\Chat\Model\ResourceModel\Conversation\CollectionFactory;

class ConversationDataProvider extends AbstractDataProvider
{
    public function __construct(
        string $name,
        string $primaryFieldName,
        string $requestFieldName,
        CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        array $meta = [],
        array $data = []
    ) {
        parent::__construct($name, $primaryFieldName, $requestFieldName, $meta, $data);
        $this->collection = $collectionFactory->create();
    }

    public function getData(): array
    {
        $collection = $this->getCollection();
        $collection->setOrder('updated_at', 'DESC');

        $storeNames = $this->buildStoreNameMap();

        $items = [];
        foreach ($collection as $item) {
            $row            = $item->getData();
            $storeId        = (int)($row['store_id'] ?? 0);
            $row['store_name'] = $storeNames[$storeId] ?? ($storeId > 0 ? "Store $storeId" : 'Default');
            $items[]        = $row;
        }

        return [
            'totalRecords' => $collection->getSize(),
            'items'        => $items,
        ];
    }

    /** @return array<int, string>  store_id → "Website / Store / View" */
    private function buildStoreNameMap(): array
    {
        $map = [0 => 'Default'];
        foreach ($this->storeManager->getStores() as $store) {
            $website  = $this->storeManager->getWebsite($store->getWebsiteId())->getName();
            $group    = $this->storeManager->getGroup($store->getStoreGroupId())->getName();
            $map[(int)$store->getId()] = $website . ' / ' . $group . ' / ' . $store->getName();
        }
        return $map;
    }
}
