<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Printess\PrintessEditor\Model\Cart\DesignPagePricing;
use Printess\PrintessEditor\Model\ProjectManager;
use Printess\PrintessEditor\Model\ThumbnailUrlValidator;
use Psr\Log\LoggerInterface;

class SaveTokenToCart implements ObserverInterface
{
    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

    /**
     * @var SerializerInterface
     */
    private SerializerInterface $serializer;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ThumbnailUrlValidator
     */
    private ThumbnailUrlValidator $thumbnailUrlValidator;

    /**
     * @var CustomerSession
     */
    private CustomerSession $customerSession;

    /**
     * @var ProjectManager
     */
    private ProjectManager $projectManager;

    /**
     * Constructor.
     *
     * @param RequestInterface      $request
     * @param SerializerInterface   $serializer
     * @param LoggerInterface       $logger
     * @param ThumbnailUrlValidator $thumbnailUrlValidator
     * @param CustomerSession       $customerSession
     * @param ProjectManager        $projectManager
     * @param DesignPagePricing     $designPagePricing server-side page pricing, shared with the reorder flows
     */
    public function __construct(
        RequestInterface $request,
        SerializerInterface $serializer,
        LoggerInterface $logger,
        ThumbnailUrlValidator $thumbnailUrlValidator,
        CustomerSession $customerSession,
        ProjectManager $projectManager,
        private readonly DesignPagePricing $designPagePricing
    ) {
        $this->request               = $request;
        $this->serializer            = $serializer;
        $this->logger                = $logger;
        $this->thumbnailUrlValidator = $thumbnailUrlValidator;
        $this->customerSession       = $customerSession;
        $this->projectManager        = $projectManager;
    }

    /**
     * Store Printess token and pricing data on the cart item.
     *
     * @param Observer $observer
     *
     * @return void
     */
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

        $validatedThumbnailUrl = $this->thumbnailUrlValidator->validate($params['thumbnailUrl'] ?? null);

        // Native save/load: keep the customer's "My Projects" list in sync on add-to-cart.
        $projectId = $this->syncProjectToDb(
            $item,
            (string)$params['saveToken'],
            $validatedThumbnailUrl,
            !empty($params['printessProjectId']) ? (int)$params['printessProjectId'] : null
        );
        if ($projectId !== null) {
            $additionalOptions['printess_project_id'] = [
                'label' => 'Printess Project ID',
                'value' => (string)$projectId,
            ];
        }

        if ($validatedThumbnailUrl !== null) {
            $additionalOptions['printess_thumbnail_url'] = [
                'label' => 'Printess Thumbnail',
                'value' => $validatedThumbnailUrl,
            ];
        }

        // Capture the product base price NOW, before setCustomPrice() is called.
        // After $quote->save() Magento's totals collector overwrites $item->getPrice()
        // with getCalculationPrice() (= custom_price), so we must snapshot it here.
        $additionalOptions['printess_base_price'] = [
            'label' => 'Printess Base Price',
            'value' => (string)(float)$item->getPrice(),
        ];

        $storeId = (int) $item->getStoreId() ?: null;
        // Only book products (page-pricing rules) are sent to /book/info (f24).
        $product = $item->getProduct();
        [$pageCount, $includedPages, $formFields] = $this->designPagePricing->fetch(
            (string)$params['saveToken'],
            $storeId,
            $product instanceof \Magento\Catalog\Model\Product ? $product : null
        );

        // Store includedPages so the cart edit flow can initialise _minPages correctly.
        if ($pageCount > 0) {
            $additionalOptions['printess_included_pages'] = [
                'label' => 'Printess Included Pages',
                'value' => (string)$includedPages,
            ];
        }

        // Store the price-relevant form fields (DOCUMENT_SIZE, COVER_TYPE, etc.) so
        // the cart edit flow can reseed them into the Printess builder on re-open.
        if (!empty($formFields) && is_array($formFields)) {
            $additionalOptions['printess_form_fields'] = [
                'label' => 'Printess Form Fields',
                'value' => json_encode($formFields),
            ];
        }

        $item->addOption([
            'product_id' => $item->getProductId(),
            'code'       => 'additional_options',
            'value'      => $this->serializer->serialize($additionalOptions),
        ]);

        // Server-side page pricing (PR #8): nothing price-relevant comes from the browser.
        $this->designPagePricing->applyToItem($item, $pageCount, $includedPages, $formFields);
    }

    /**
     * Create or update the customer's Magento project record for this design.
     *
     * Returns the project id, or null for guests / on failure (never blocks add-to-cart).
     */
    private function syncProjectToDb($item, string $saveToken, ?string $thumbnailUrl, ?int $projectId): ?int
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        $productId  = (int)$item->getProductId();

        try {
            $project = $this->projectManager->save($customerId, $productId, $saveToken, $thumbnailUrl, $projectId);
            return (int)$project->getId() ?: null;
        } catch (\Throwable $e) {
            if ($projectId !== null) {
                // Stale/foreign project id: fall back to creating a fresh project.
                try {
                    $project = $this->projectManager->save($customerId, $productId, $saveToken, $thumbnailUrl);
                    return (int)$project->getId() ?: null;
                } catch (\Throwable $e2) {
                    $e = $e2;
                }
            }
            $this->logger->warning('Printess: project DB sync on cart add failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Return decoded request parameters (JSON body or POST params).
     *
     * @return array
     */
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
