<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Plugin\Sales;

use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Reorder\Data\Error;
use Magento\Sales\Model\Reorder\Data\ReorderOutput;
use Magento\Sales\Model\Reorder\Reorder;
use Printess\PrintessEditor\Model\Cart\PrintessOptionsCopier;
use Printess\PrintessEditor\Model\Cart\ReorderContext;
use Psr\Log\LoggerInterface;

/**
 * PMD-23: the native storefront / GraphQL "Reorder" rebuilds items via Quote::addProduct and never fires
 * checkout_cart_product_add_after, so Printess items would lose their design. This plugin stashes the Printess order
 * items in ReorderContext for the duration of the reorder (Plugin\Quote\AddPrintessDesignOnReorder does the copying)
 * and reports items whose design expired — through the reorder output and, on the storefront, the message manager
 * (the native Reorder controller does not display the output errors).
 */
class KeepPrintessDesignOnReorder
{
    private const ERROR_CODE = 'NOT_SALABLE';

    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly ReorderContext $context,
        private readonly PrintessOptionsCopier $copier,
        private readonly MessageManager $messageManager,
        private readonly AppState $appState,
        private readonly LoggerInterface $logger
    ) {
    }

    public function aroundExecute(Reorder $subject, callable $proceed, string $orderNumber, string $storeId): ReorderOutput
    {
        if ($this->context->isActive()) {
            return $proceed($orderNumber, $storeId);
        }

        $sources = [];
        $order = null;
        try {
            $order = $this->orderFactory->create()->loadByIncrementIdAndStoreId($orderNumber, $storeId);
            if ($order->getId()) {
                $sources = $this->copier->buildSources($order);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: reading order for reorder failed', ['error' => $e->getMessage()]);
        }
        if ($sources === [] || !$order) {
            return $proceed($orderNumber, $storeId);
        }

        $this->context->start((int) $order->getStoreId(), (int) $order->getCustomerId(), $sources);
        try {
            /** @var ReorderOutput $output */
            $output = $proceed($orderNumber, $storeId);
            $messages = $this->context->getErrors();
        } finally {
            $this->context->stop();
        }

        if ($messages === []) {
            return $output;
        }

        $errors = $output->getErrors();
        foreach ($messages as $message) {
            $errors[] = new Error($message, self::ERROR_CODE);
            if ($this->isStorefront()) {
                $this->messageManager->addErrorMessage($message);
            }
        }
        return new ReorderOutput($output->getCart(), $errors);
    }

    private function isStorefront(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (\Throwable) {
            return false;
        }
    }
}
