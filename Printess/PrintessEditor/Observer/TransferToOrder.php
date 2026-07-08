<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Printess\PrintessEditor\Model\AdditionalOptions\OptionsManager;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class TransferToOrder implements ObserverInterface
{
    private OptionsManager $optionsManager;

    public function __construct(OptionsManager $optionsManager)
    {
        $this->optionsManager = $optionsManager;
    }

    public function execute(Observer $observer): void
    {
        $this->optionsManager->transferAdditionalOptions(
            $observer->getData('quote'),
            $observer->getData('order')
        );
    }
}
