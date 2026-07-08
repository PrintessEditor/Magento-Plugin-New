<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PrintessApi;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class ProducePdf implements ObserverInterface
{
    private Config $config;
    private Filesystem $filesystem;
    private OrderRepositoryInterface $orderRepository;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        Filesystem $filesystem,
        OrderRepositoryInterface $orderRepository,
        LoggerInterface $logger
    ) {
        $this->config          = $config;
        $this->filesystem      = $filesystem;
        $this->orderRepository = $orderRepository;
        $this->logger          = $logger;
    }

    public function execute(Observer $observer): void
    {
        $order        = $observer->getEvent()->getOrder();
        $serviceToken = $this->config->getServiceToken();

        if (empty($serviceToken)) {
            $this->logger->warning(
                'Printess: no service token configured — skipping PDF production for order ' . $order->getId()
            );
            $order->addCommentToStatusHistory(
                'Printess: PDF production skipped — service token not configured.'
            );
            $this->orderRepository->save($order);
            return;
        }

        $api           = new PrintessApi($serviceToken);
        $printSettings = $this->config->getPrintSettings();
        $comments      = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $options   = $item->getProductOptions();
            $saveToken = $options['additional_options']['printess_save_token']['value'] ?? null;

            if (empty($saveToken)) {
                continue;
            }

            try {
                $jobId    = $api->produce($saveToken, (string)$order->getId(), $printSettings);
                $pdfUrl   = $api->pollUntilDone($jobId);
                $filePath = $this->savePdf($pdfUrl, $order->getId(), $item->getId());

                $comments[] = '✓ ' . $item->getName() . ' — PDF saved to var/' . $filePath;
                $this->logger->info('Printess: PDF saved to var/' . $filePath);
            } catch (\Exception $e) {
                $msg = '✗ ' . $item->getName() . ' — PDF production failed: ' . $e->getMessage();
                $comments[] = $msg;
                $this->logger->error(
                    'Printess PDF production failed for order ' . $order->getId() .
                    ', item ' . $item->getId() . ': ' . $e->getMessage()
                );
            }
        }

        if (!empty($comments)) {
            $order->addCommentToStatusHistory(
                'Printess PDF Production:' . "\n" . implode("\n", $comments)
            );
            $this->orderRepository->save($order);
        }
    }

    private function savePdf(string $pdfUrl, $orderId, $itemId): string
    {
        $varDir   = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $dir      = 'printess/order_' . $orderId;
        $filePath = $dir . '/item_' . $itemId . '.pdf';

        $varDir->create($dir);

        $content = @file_get_contents($pdfUrl);
        if ($content === false) {
            throw new \RuntimeException('Could not download PDF from ' . $pdfUrl);
        }

        $varDir->writeFile($filePath, $content);

        return $filePath;
    }
}
