<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Printess\PrintessEditor\Model\ResourceModel\Project as ProjectResource;
use Printess\PrintessEditor\Model\ResourceModel\Project\CollectionFactory;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;

class MarkSavedProjectOrdered implements ObserverInterface
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly ProjectResource $projectResource,
        private readonly DateTime $dateTime
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();
        if (!$order instanceof Order || !(int) $order->getCustomerId()) {
            return;
        }

        $tokens = $this->getSaveTokens($order);
        if (empty($tokens)) {
            return;
        }

        $projects = $this->collectionFactory->create();
        $projects->addFieldToFilter('customer_id', (int) $order->getCustomerId());
        $projects->addFieldToFilter('save_token', ['in' => $tokens]);
        $projects->addFieldToFilter('ordered_at', ['null' => true]);

        foreach ($projects as $project) {
            try {
                $project->setData('ordered_at', $this->dateTime->gmtDate());
                $this->projectResource->save($project);
            } catch (\Throwable) {
                // Project bookkeeping must never interrupt order placement.
            }
        }
    }

    private function getSaveTokens(Order $order): array
    {
        $tokens = [];
        foreach ($order->getAllItems() as $item) {
            $options = $item->getProductOptions();
            if (!is_array($options)) {
                continue;
            }
            $saveToken = (string) ($options['additional_options']['printess_save_token']['value'] ?? '');
            if ($saveToken !== '') {
                $tokens[$saveToken] = true;
            }
        }

        return array_keys($tokens);
    }
}
