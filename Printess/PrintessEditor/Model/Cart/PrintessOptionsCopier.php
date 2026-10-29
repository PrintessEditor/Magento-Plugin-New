<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Cart;

use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Reorder\OrderInfoBuyRequestGetter;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Printess\PrintessEditor\Model\ProjectManager;
use Printess\PrintessEditor\Model\ResourceModel\Project\CollectionFactory as ProjectCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * PMD-23: carries the Printess design of a past order item onto the quote item a reorder creates — the
 * additional_options (save token, thumbnail), the page pricing of the original add-to-cart, and the "My Projects"
 * entry — and checks whether the design's save token is still active.
 */
class PrintessOptionsCopier
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly OrderInfoBuyRequestGetter $buyRequestGetter,
        private readonly PrintessApiProvider $apiProvider,
        private readonly DesignPagePricing $designPagePricing,
        private readonly ProjectManager $projectManager,
        private readonly ProjectCollectionFactory $projectCollectionFactory,
        private readonly CustomerSession $customerSession,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Reorder sources (see ReorderContext::start) for every top-level order item carrying a Printess save token.
     *
     * @return list<array{key: string, product_id: int, buy_request: array<string, mixed>, options: array<string, mixed>, name: string}>
     */
    public function buildSources(Order $order): array
    {
        $sources = [];
        foreach ($order->getItemsCollection() as $orderItem) {
            if ($orderItem->getParentItem() !== null || $orderItem->getParentItemId()) {
                continue;
            }
            try {
                $options = ReorderContext::extractPrintessOptions($orderItem->getProductOptionByCode('additional_options'));
            } catch (\Throwable) {
                $options = [];
            }
            if ($options === []) {
                continue;
            }
            $sources[] = [
                'key'         => (string) $orderItem->getItemId(),
                'product_id'  => (int) $orderItem->getProductId(),
                'buy_request' => (array) $this->buyRequestGetter->getInfoBuyRequest($orderItem)->getData(),
                'options'     => $options,
                'name'        => (string) $orderItem->getName(),
            ];
        }
        return $sources;
    }

    /**
     * Tri-state save token check (PrintessApi::TOKEN_STATE_*). Only a definite answer from Printess counts as
     * "inactive"; a missing service token, network error, HTTP 5xx etc. is "unknown" and logged, so the caller can
     * keep the design instead of telling the customer it expired.
     */
    public function getSaveTokenState(?int $storeId, string $saveToken): string
    {
        try {
            $state = $this->apiProvider->forStore($storeId)->getSaveTokenState($saveToken);
            $error = $state === PrintessApi::TOKEN_STATE_UNKNOWN ? 'Printess API unavailable or returned an error' : '';
        } catch (\Throwable $e) {
            $state = PrintessApi::TOKEN_STATE_UNKNOWN;
            $error = $e->getMessage();
        }
        if ($state === PrintessApi::TOKEN_STATE_UNKNOWN) {
            $this->logger->warning(
                'Printess: save token state on reorder unknown, keeping the design',
                ['store_id' => $storeId, 'error' => $error]
            );
        }
        return $state;
    }

    /**
     * Puts the Printess options on the product as a custom option before Quote::addProduct, so the candidate only
     * merges with a cart line carrying the same design (never with a plain line of the same product).
     *
     * @param array<string, mixed> $printessOptions
     */
    public function prepareProduct(Product $product, array $printessOptions): void
    {
        $product->addCustomOption('additional_options', $this->serializer->serialize($printessOptions));
    }

    /**
     * Ensures the (parent) quote item carries the Printess options and re-applies page pricing server-side from the
     * design (/book/info, PR #8), with the old order's option/variant selections from the buy request.
     *
     * @param array<string, mixed> $printessOptions
     * @param array<string, mixed> $buyRequest
     */
    public function applyToItem(QuoteItem $item, array $printessOptions, array $buyRequest): void
    {
        $item = $item->getParentItem() ?: $item;

        $additional = [];
        if ($existing = $item->getOptionByCode('additional_options')) {
            try {
                $additional = (array) $this->serializer->unserialize((string) $existing->getValue());
            } catch (\Throwable) {
                $additional = [];
            }
        }
        $merged = array_merge($additional, $printessOptions);
        if ($merged !== $additional || !$existing) {
            $item->addOption([
                'product_id' => $item->getProductId(),
                'code'       => 'additional_options',
                'value'      => $this->serializer->serialize($merged),
            ]);
        }

        $token = (string) ($printessOptions['printess_save_token']['value'] ?? '');
        if ($token !== '') {
            $this->designPagePricing->applyFromDesign($item, $token, (int) $item->getStoreId() ?: null, $buyRequest);
        }
    }

    /**
     * Same "My Projects" bookkeeping as an add-to-cart (SaveTokenToCart), but refreshes the customer's existing
     * project for this save token instead of creating a duplicate. Logged-in customer of the order only.
     *
     * @param array<string, mixed> $printessOptions
     */
    public function syncProject(int $orderCustomerId, int $productId, array $printessOptions): void
    {
        if ($orderCustomerId <= 0 || !$this->customerSession->isLoggedIn()
            || (int) $this->customerSession->getCustomerId() !== $orderCustomerId
        ) {
            return;
        }
        $token = (string) ($printessOptions['printess_save_token']['value'] ?? '');
        $thumb = (string) ($printessOptions['printess_thumbnail_url']['value'] ?? '');
        if ($token === '') {
            return;
        }

        try {
            $collection = $this->projectCollectionFactory->create();
            $collection->addFieldToFilter('customer_id', $orderCustomerId);
            $collection->addFieldToFilter('product_id', $productId);
            $collection->addFieldToFilter('save_token', $token);
            $collection->setPageSize(1);
            $existing = $collection->getFirstItem();
            $projectId = $existing && $existing->getId() ? (int) $existing->getId() : null;

            $this->projectManager->save($orderCustomerId, $productId, $token, $thumb !== '' ? $thumb : null, $projectId);
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: project DB sync on reorder failed', ['error' => $e->getMessage()]);
        }
    }
}
