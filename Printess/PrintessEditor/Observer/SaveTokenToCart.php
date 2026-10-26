<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Printess\PrintessEditor\Model\Cart\PagePricing;
use Printess\PrintessEditor\Model\ProjectManager;
use Psr\Log\LoggerInterface;

class SaveTokenToCart implements ObserverInterface
{
    private RequestInterface $request;
    private SerializerInterface $serializer;
    private LoggerInterface $logger;
    private CustomerSession $customerSession;
    private ProjectManager $projectManager;
    private PagePricing $pagePricing;

    public function __construct(
        RequestInterface $request,
        SerializerInterface $serializer,
        LoggerInterface $logger,
        CustomerSession $customerSession,
        ProjectManager $projectManager,
        PagePricing $pagePricing
    )
    {
        $this->request         = $request;
        $this->serializer      = $serializer;
        $this->logger          = $logger;
        $this->customerSession = $customerSession;
        $this->projectManager  = $projectManager;
        $this->pagePricing     = $pagePricing;
    }

    public function execute(Observer $observer): void
    {
        $params = $this->getParams();

        if (empty($params['saveToken'])) {
            return;
        }

        $item = $observer->getQuoteItem();
        $item = $item->getParentItem() ?: $item;

        $additionalOptions = [];
        if ($existing = $item->getOptionByCode('additional_options')) {
            $additionalOptions = $this->serializer->unserialize($existing->getValue());
        }

        $additionalOptions['printess_save_token'] = [
            'label' => 'Printess Save Token',
            'value' => $params['saveToken'],
        ];

        if (!empty($params['thumbnailUrl'])) {
            $additionalOptions['printess_thumbnail_url'] = [
                'label' => 'Printess Thumbnail',
                'value' => $params['thumbnailUrl'],
            ];
        }

        $item->addOption([
            'product_id' => $item->getProductId(),
            'code'       => 'additional_options',
            'value'      => $this->serializer->serialize($additionalOptions),
        ]);

        $this->syncProjectToDb($item, $params['saveToken'], $params['thumbnailUrl'] ?? null);

        $this->pagePricing->apply($item, $params);
    }

    private function syncProjectToDb($item, string $saveToken, ?string $thumbnailUrl): void
    {
        if (!$this->customerSession->isLoggedIn()) {
            return;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $productId  = (int) $item->getProductId();

        try {
            $this->projectManager->save(
                $customerId,
                $productId,
                $saveToken,
                $thumbnailUrl ?: null
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: project DB sync on cart add failed', ['error' => $e->getMessage()]);
        }
    }

    private function getParams(): array
    {
        $content = $this->request->getContent();
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $this->request->getPostValue() ?: [];
    }
}
