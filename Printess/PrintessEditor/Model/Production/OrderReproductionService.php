<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject;
use Magento\Framework\DataObject\Copy as ObjectCopyService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Model\Config as TaxConfig;
use Printess\PrintessEditor\Model\Carrier\Reproduction as ReproductionCarrier;
use Psr\Log\LoggerInterface;

/**
 * Reproduces dropship items as a COPY of the order (PMD-21, plan §1.5, spike S1) — for dropshippers that refuse an
 * order number they have received before. The Magento counterpart of Shopware's PrintessOrderReproductionService.
 *
 * The copy is a real order built through a quote, so Magento does grid, customer account, totals and MSI as usual:
 * same store, currency, customer (or guest e-mail) and addresses; the chosen items with their stored buy request,
 * their Printess additional_options (save token, thumbnail, …) and their ORIGINAL unit price (custom price). It is free
 * for the customer: the `printess_reproduction` total collector adds a discount, titled with the merchant's message,
 * that offsets the items exactly; shipping is the hidden 0-cost carrier titled like the original; payment is
 * Zero Subtotal Checkout (`free`), invoiced offline so the copy is paid and processing. If the collected quote is not
 * exactly 0 (tax or grand total, e.g. FPT or a third-party fee), it is rebuilt with every item at custom price 0
 * (S1 Option C) and `discount_path` = `zero_price` is recorded.
 *
 * No new-order e-mail is sent. The message becomes a status-history comment visible to the customer (not notified).
 * Both orders are linked (`sales_order.printess_reproduction_of` on the copy, `printess_reproduced_as` on the
 * original; format in ReproductionLink). Production is enqueued by EnqueuePdfJobs as for any order.
 *
 * Placing the order is atomic (QuoteManagement::submit). Steps after that — stock compensation, link, invoice — are
 * best effort: a failure is logged and noted on the copy, and the copy is still returned.
 *
 * One copy per original order at a time (lock `printess_reproduce_<orderId>`), and the same item set is not copied
 * twice within DOUBLE_SUBMIT_SECONDS (a double POST). The copy uses the original's order currency; when the store no
 * longer allows it, the copy is refused instead of mixing currencies.
 */
class OrderReproductionService
{
    /** Default message to the customer, pre-filled in the admin form. */
    public const DEFAULT_MESSAGE = 'Due to dropship issues we needed to recreate the order.';

    /** Quote item option holding the original order item id; keeps copied items from merging and maps them back. */
    public const SOURCE_ITEM_OPTION = 'printess_reproduction_source_item';

    /** A copy of the same items of the same order within this many seconds is taken for a double submit. */
    public const DOUBLE_SUBMIT_SECONDS = 60;

    private const LOCK_PREFIX = 'printess_reproduce_';

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteManagement $quoteManagement,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly CurrencyFactory $currencyFactory,
        private readonly ObjectCopyService $objectCopyService,
        private readonly SerializerInterface $serializer,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory,
        private readonly TaxConfig $taxConfig,
        private readonly ResourceConnection $resource,
        private readonly ReproductionContext $context,
        private readonly ReproductionStockCompensator $stockCompensator,
        private readonly LockManagerInterface $lockManager,
        private readonly LoggerInterface $logger,
        private readonly DesignAvailabilityCheck $designCheck
    ) {
    }

    /**
     * @param list<int|string> $orderItemIds items of the original to copy (top-level Printess items; a child id
     *                                       stands for its parent; others are ignored)
     * @param string $message shown to the customer on the copy (discount title and order comment)
     * @param bool $reduceStock false: give back the stock the copy reserves
     * @return array{id: int, increment_id: string}
     * @throws LocalizedException order missing, no valid Printess items, empty message, a copy already in progress or
     *                            just created, currency not allowed anymore, or the copy could not be placed
     */
    public function copy(int $orderId, array $orderItemIds, string $message, bool $reduceStock): array
    {
        $lockName = self::LOCK_PREFIX . $orderId;
        if (!$this->lockManager->lock($lockName, 0)) {
            throw new LocalizedException(__('A copy of this order is already being created.'));
        }
        try {
            return $this->copyLocked($orderId, $orderItemIds, $message, $reduceStock);
        } finally {
            try {
                $this->lockManager->unlock($lockName);
            } catch (\Throwable $e) {
                $this->logger->warning('Printess: could not release the order copy lock', [
                    'order_id' => $orderId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<int|string> $orderItemIds
     * @return array{id: int, increment_id: string}
     * @throws LocalizedException
     */
    private function copyLocked(int $orderId, array $orderItemIds, string $message, bool $reduceStock): array
    {
        $message = trim($message);
        if ($message === '') {
            throw new LocalizedException(__('Enter the message to the customer for the copy of the order.'));
        }

        try {
            /** @var Order $original */
            $original = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('The order to copy does not exist anymore.'));
        }
        if (!$original->getEntityId()) {
            throw new LocalizedException(__('The order to copy does not exist anymore.'));
        }

        $items = $this->selectItems($original, $orderItemIds);
        if ($items === []) {
            throw new LocalizedException(__('There are no Printess items of this order to copy.'));
        }
        // A copy of an expired design could never be produced: refuse before the copy order exists (D11/f22).
        $this->designCheck->assertAvailable(
            (int) $original->getStoreId(),
            array_map(fn (OrderItem $i) => [
                'label' => (string) $i->getSku() !== '' ? (string) $i->getSku() : '#' . (int) $i->getItemId(),
                'token' => $this->saveToken($i),
            ], $items),
            'copied'
        );
        $this->assertNotJustCopied(
            (int) $original->getEntityId(),
            array_map(static fn (OrderItem $i) => (int) $i->getItemId(), $items)
        );

        $customer = $this->resolveCustomer($original);
        $store = $this->storeManager->getStore((int) $original->getStoreId());

        $this->context->start(
            (int) $original->getEntityId(),
            $message,
            (string) $original->getShippingDescription()
        );
        $restoreCurrency = static function (): void {
        };
        $quote = null;
        try {
            $restoreCurrency = $this->switchStoreCurrency($store, (string) $original->getOrderCurrencyCode());
            $path = ReproductionLink::DISCOUNT_PATH_COLLECTOR;
            $quote = $this->buildQuote($original, $items, $customer, $store, false, $reduceStock);
            if (!$this->isZeroTotal($quote)) {
                $this->logger->warning('Printess: reproduction discount did not zero the copy, using price 0', [
                    'order_id'    => (int) $original->getEntityId(),
                    'grand_total' => (float) $quote->getGrandTotal(),
                    'tax_amount'  => (float) $this->totalsAddress($quote)->getTaxAmount(),
                ]);
                $path = ReproductionLink::DISCOUNT_PATH_ZERO_PRICE;
                $this->context->setDiscountEnabled(false);
                $quote = $this->buildQuote($original, $items, $customer, $store, true, $reduceStock);
                if (!$this->isZeroTotal($quote)) {
                    throw new LocalizedException(__(
                        'The copy of the order would not be free (total %1, tax %2), so it was not created.',
                        (float) $quote->getGrandTotal(),
                        (float) $this->totalsAddress($quote)->getTaxAmount()
                    ));
                }
            }

            // Zero Subtotal Checkout; Plugin\Payment\AllowFreeForReproduction makes it available even when disabled.
            // The quote is not saved yet, and Quote::getPayment() only attaches itself to the payment once it has an
            // id; without the quote, importData() fails in Payment::getMethodInstance() ("getStoreId() on null").
            $payment = $quote->getPayment();
            $payment->setQuote($quote);
            $payment->importData(['method' => 'free', 'checks' => []]);

            $this->context->setPendingOrderData([
                'printess_reproduction_of' => ReproductionStockCompensator::withStockReduced(
                    ReproductionLink::buildReproductionOf(
                        (int) $original->getEntityId(),
                        (string) $original->getIncrementId(),
                        $original->getCustomerNote() !== null ? (string) $original->getCustomerNote() : null,
                        [],
                        $path,
                        (string) $original->getShippingMethod(),
                        (string) $original->getShippingDescription(),
                        $message
                    ),
                    $reduceStock
                ),
                // The shipping code sent to Printess (JobSubmitter) stays the original's.
                'shipping_method' => $quote->isVirtual() ? null : (string) $original->getShippingMethod(),
            ]);

            $this->cartRepository->save($quote);
            $sourceByQuoteItem = $this->sourceItemsByQuoteItemId($quote);

            /** @var Order|null $copy */
            $copy = $this->quoteManagement->submit($quote);
            if (!$copy || !$copy->getEntityId()) {
                throw new LocalizedException(__('The copy of the order could not be placed.'));
            }
        } catch (\Throwable $e) {
            $this->discardQuote($quote);
            if ($e instanceof LocalizedException) {
                throw $e;
            }
            $this->logger->error('Printess: placing the order copy failed', [
                'order_id' => $orderId, 'error' => $e->getMessage(), 'exception' => $e,
            ]);
            throw new LocalizedException(
                __('The copy of the order could not be created: %1', $e->getMessage()),
                $e instanceof \Exception ? $e : null // the cause must be an Exception; an Error (TypeError, ...) is logged above
            );
        } finally {
            $this->context->stop();
            $restoreCurrency();
        }

        $this->finish($original, $copy, $items, $sourceByQuoteItem, $path, $message, $reduceStock);

        return ['id' => (int) $copy->getEntityId(), 'increment_id' => (string) $copy->getIncrementId()];
    }

    /**
     * Everything after placement. Each step is isolated: a failure is logged and noted on the copy.
     *
     * @param list<OrderItem> $items
     * @param array<int, int> $sourceByQuoteItem quote item id => original order item id
     */
    private function finish(
        Order $original,
        Order $copy,
        array $items,
        array $sourceByQuoteItem,
        string $path,
        string $message,
        bool $reduceStock
    ): void {
        $originalId = (int) $original->getEntityId();
        $copyId = (int) $copy->getEntityId();
        $problems = [];

        // 1. Stock. First, so the link records whether the copy's stock is reduced: cancelling or refunding a copy
        //    with stock_reduced=false takes back what Magento returns (Observer\CompensateReproduction*).
        $stockReduced = true;
        $stockNote = (string) __('Stock was reduced for the copy.');
        if (!$reduceStock) {
            try {
                $result = $this->stockCompensator->compensate($copy);
                $stockReduced = false;
                $stockNote = $result === ReproductionStockCompensator::RESULT_MSI
                    ? (string) __('Stock was not reduced for the copy (compensating reservations placed).')
                    : (string) __('Stock was not reduced for the copy.');
            } catch (\Throwable $e) {
                $problems[] = $this->problem('give back the stock of the copy', $copyId, $e);
                $stockNote = (string) __('Stock should not have been reduced, but giving it back failed; adjust it manually.');
            }
        }

        // 2. Final link on the copy (with the item map) and the customer message.
        $itemMap = [];
        foreach ($copy->getAllItems() as $copyItem) {
            if ($copyItem->getParentItemId() || $copyItem->getParentItem()) {
                continue;
            }
            $source = $sourceByQuoteItem[(int) $copyItem->getQuoteItemId()] ?? null;
            if ($source !== null) {
                $itemMap[$source] = (int) $copyItem->getItemId();
            }
        }
        try {
            $copy->setData('printess_reproduction_of', ReproductionStockCompensator::withStockReduced(
                ReproductionLink::buildReproductionOf(
                    $originalId,
                    (string) $original->getIncrementId(),
                    $original->getCustomerNote() !== null ? (string) $original->getCustomerNote() : null,
                    $itemMap,
                    $path,
                    (string) $original->getShippingMethod(),
                    (string) $original->getShippingDescription(),
                    $message
                ),
                $stockReduced
            ));
            $copy->addCommentToStatusHistory($message, false, true)->setIsCustomerNotified(false);
            $this->orderRepository->save($copy);
        } catch (\Throwable $e) {
            $problems[] = $this->problem('link the copy', $copyId, $e);
        }

        // 3. The original lists its copies (also on the in-memory original, so later saves keep the entry).
        try {
            $original->setData('printess_reproduced_as', $this->appendReproducedAs(
                $originalId,
                $copy,
                array_map(static fn (OrderItem $i) => (int) $i->getItemId(), $items)
            ));
            $history = $original->addCommentToStatusHistory(__(
                'Printess: items %1 reproduced as order #%2.',
                implode(', ', array_map(static fn (OrderItem $i) => '"' . $i->getName() . '"', $items)),
                $copy->getIncrementId()
            ), false, false);
            $history->setIsCustomerNotified(false);
            $this->historyRepository->save($history);
        } catch (\Throwable $e) {
            $problems[] = $this->problem('link the original order', $copyId, $e);
        }

        // 4. Paid: offline invoice (unless the payment already invoiced it).
        try {
            $this->invoice($copy);
        } catch (\Throwable $e) {
            $problems[] = $this->problem('invoice the copy', $copyId, $e);
        }

        // 5. Admin note.
        $note = [(string) __(
            'Printess: copy of order #%1 to reproduce dropship items. No order e-mail was sent.',
            $original->getIncrementId()
        ), $stockNote];
        if ($path === ReproductionLink::DISCOUNT_PATH_ZERO_PRICE) {
            $prices = [];
            foreach ($items as $item) {
                $prices[] = sprintf(
                    '"%s": %s x %s',
                    $item->getName(),
                    (float) $item->getQtyOrdered(),
                    $original->formatPriceTxt((float) $item->getPrice())
                );
            }
            $note[] = (string) __(
                'The discount line could not bring the total to 0, so the items were copied at price 0. Original prices: %1.',
                implode('; ', $prices)
            );
        }
        foreach ($problems as $problem) {
            $note[] = $problem;
        }
        $this->addNote($copy, implode(' ', $note));
    }

    /**
     * @param list<int|string> $requestedIds
     * @return list<OrderItem>
     */
    private function selectItems(Order $order, array $requestedIds): array
    {
        $byId = [];
        $candidates = [];
        foreach ($order->getAllItems() as $item) {
            $id = (int) $item->getItemId();
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = $item;
            $parentId = (int) $item->getParentItemId();
            $candidates[$id] = [
                'parent_id' => $parentId > 0 ? $parentId : null,
                'printess'  => $parentId <= 0 && $this->saveToken($item) !== '',
            ];
        }
        $selected = [];
        foreach (ReproductionLink::selectItemIds($requestedIds, $candidates) as $id) {
            $selected[] = $byId[$id];
        }
        return $selected;
    }

    private function saveToken(OrderItem $item): string
    {
        $options = $item->getProductOptions();
        $token = is_array($options) ? ($options['additional_options']['printess_save_token']['value'] ?? '') : '';
        return is_scalar($token) ? trim((string) $token) : '';
    }

    private function resolveCustomer(Order $order): ?CustomerInterface
    {
        $customerId = (int) $order->getCustomerId();
        if ($customerId <= 0 || $order->getCustomerIsGuest()) {
            return null;
        }
        try {
            return $this->customerRepository->getById($customerId);
        } catch (NoSuchEntityException $e) {
            return null; // deleted since: the copy is a guest order for the original e-mail
        }
    }

    /**
     * @param list<OrderItem> $items
     * @throws LocalizedException
     */
    private function buildQuote(
        Order $original,
        array $items,
        ?CustomerInterface $customer,
        Store $store,
        bool $zeroPrice,
        bool $reduceStock
    ): Quote {
        /** @var Quote $quote */
        $quote = $this->quoteFactory->create();
        $quote->setStore($store);
        $quote->setIsActive(false);
        // Like an admin-created order: no old-qty bookkeeping, super mode for prices and options.
        $quote->setIsSuperMode(true);
        $quote->setIgnoreOldQty(true);
        $quote->setData('printess_reproduction_of', (int) $original->getEntityId());
        $currencyCode = (string) $original->getOrderCurrencyCode();
        if ($currencyCode !== '') {
            $quote->setForcedCurrency($this->currencyFactory->create()->load($currencyCode));
        }
        if (!$reduceStock) {
            // Legacy CatalogInventory skips the decrement (SubtractQuoteInventoryObserver); MSI is compensated later.
            $quote->setInventoryProcessed(true);
        }

        if ($customer !== null) {
            $quote->setCustomer($customer);
            $quote->setCustomerIsGuest(false);
        } else {
            $quote->setCustomerId(null);
            $quote->setCustomerIsGuest(true);
            $quote->setCustomerEmail((string) $original->getCustomerEmail());
            $quote->setCustomerFirstname($original->getCustomerFirstname());
            $quote->setCustomerMiddlename($original->getCustomerMiddlename());
            $quote->setCustomerLastname($original->getCustomerLastname());
            $quote->setCustomerPrefix($original->getCustomerPrefix());
            $quote->setCustomerSuffix($original->getCustomerSuffix());
            $quote->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
        }
        $quote->setCustomerNoteNotify(false);

        $priceIncludesTax = (bool) $this->taxConfig->priceIncludesTax($store);
        foreach ($items as $orderItem) {
            $this->addItem($quote, $orderItem, $store, $zeroPrice, $priceIncludesTax);
        }

        $this->copyAddresses($quote, $original, $customer);
        if (!$quote->isVirtual()) {
            $shipping = $quote->getShippingAddress();
            $shipping->setCollectShippingRates(true);
            $shipping->setShippingMethod(ReproductionCarrier::METHOD_CODE);
        }

        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        return $quote;
    }

    /**
     * @throws LocalizedException
     */
    private function addItem(Quote $quote, OrderItem $orderItem, Store $store, bool $zeroPrice, bool $priceIncludesTax): void
    {
        try {
            $product = $this->productRepository->getById((int) $orderItem->getProductId(), false, (int) $store->getId(), true);
        } catch (NoSuchEntityException $e) {
            throw new LocalizedException(__('The product of item "%1" does not exist anymore.', $orderItem->getName()));
        }
        $product->setSkipCheckRequiredOption(true);

        $buyRequest = $orderItem->getBuyRequest();
        $buyRequest->setQty((float) $orderItem->getQtyOrdered());
        $buyRequest->unsetData('uenc');
        $buyRequest->unsetData('form_key');
        $this->formatCustomOptions($product, $buyRequest, $orderItem->getProductOptions());

        $item = $quote->addProduct($product, $buyRequest);
        if (is_string($item) || !$item) {
            throw new LocalizedException(__(
                'Item "%1" could not be added to the copy: %2',
                $orderItem->getName(),
                is_string($item) ? $item : ''
            ));
        }

        // The Printess design travels exactly as in a normal checkout: additional_options → order item options.
        $options = $orderItem->getProductOptions();
        $additional = is_array($options) ? ($options['additional_options'] ?? null) : null;
        if (is_array($additional) && $additional !== []) {
            $item->addOption(new DataObject([
                'product' => $item->getProduct(),
                'code'    => 'additional_options',
                'value'   => $this->serializer->serialize($additional),
            ]));
        }
        $item->addOption(new DataObject([
            'product' => $item->getProduct(),
            'code'    => self::SOURCE_ITEM_OPTION,
            'value'   => (string) $orderItem->getItemId(),
        ]));

        $price = $zeroPrice ? 0.0 : $this->unitPrice($orderItem, $priceIncludesTax);
        $item->setCustomPrice($price);
        $item->setOriginalCustomPrice($price);
        $item->getProduct()->setIsSuperMode(true);
        if ($zeroPrice) {
            foreach ($item->getChildren() ?: [] as $child) {
                $child->setCustomPrice(0.0);
                $child->setOriginalCustomPrice(0.0);
                $child->getProduct()->setIsSuperMode(true);
            }
        }
    }

    /** The original unit price in order currency, as a custom price is read: incl. tax when catalog prices are. */
    private function unitPrice(OrderItem $item, bool $priceIncludesTax): float
    {
        if ($priceIncludesTax && $item->getPriceInclTax() !== null) {
            return (float) $item->getPriceInclTax();
        }
        return (float) $item->getPrice();
    }

    /**
     * Custom options as the product type expects them (as AdminOrder\Create::formattedOptions does).
     *
     * @param array<string, mixed>|null $productOptions
     */
    private function formatCustomOptions($product, DataObject $buyRequest, $productOptions): void
    {
        if (!is_array($productOptions) || empty($productOptions['options']) || !is_array($productOptions['options'])) {
            return;
        }
        $formatted = [];
        $requested = $buyRequest->getData('options');
        foreach ($productOptions['options'] as $option) {
            if (!isset($option['option_id'])) {
                continue;
            }
            $optionId = $option['option_id'];
            $type = (string) ($option['option_type'] ?? '');
            if (in_array($type, ['date', 'date_time', 'time', 'file'], true)) {
                $product->setSkipCheckRequiredOption(false);
                if ($type === 'file') {
                    try {
                        $formatted[$optionId] = $this->serializer->unserialize((string) ($option['option_value'] ?? ''));
                        continue;
                    } catch (\InvalidArgumentException $e) {
                        $this->logger->warning($e->getMessage());
                    }
                }
                if (is_array($requested) && array_key_exists($optionId, $requested)) {
                    $formatted[$optionId] = $requested[$optionId];
                }
                continue;
            }
            $formatted[$optionId] = $option['option_value'] ?? null;
        }
        if ($formatted !== []) {
            $buyRequest->setData('options', $formatted);
        }
    }

    private function copyAddresses(Quote $quote, Order $original, ?CustomerInterface $customer): void
    {
        $billingSource = $original->getBillingAddress();
        $shippingSource = $original->getShippingAddress() ?: $billingSource;
        if (!$billingSource) {
            throw new LocalizedException(__('The order to copy has no billing address.'));
        }

        $billing = $quote->getBillingAddress();
        $this->objectCopyService->copyFieldsetToTarget('sales_copy_order_billing_address', 'to_order', $billingSource, $billing);
        $this->prepareAddress($billing, $customer, (string) $original->getCustomerEmail());

        if (!$quote->isVirtual() && $shippingSource) {
            $shipping = $quote->getShippingAddress();
            $fieldset = $original->getShippingAddress() ? 'sales_copy_order_shipping_address' : 'sales_copy_order_billing_address';
            $this->objectCopyService->copyFieldsetToTarget($fieldset, 'to_order', $shippingSource, $shipping);
            $this->prepareAddress($shipping, $customer, (string) $original->getCustomerEmail());
            $shipping->setSameAsBilling(0);
        }
    }

    /** No new address-book entries for the customer (QuoteManagement::_prepareCustomerQuote skips these). */
    private function prepareAddress(Quote\Address $address, ?CustomerInterface $customer, string $email): void
    {
        $address->setCustomerAddressId(null);
        $address->setSaveInAddressBook(0);
        $address->setCustomerId($customer?->getId());
        if (!$address->getEmail() && $email !== '') {
            $address->setEmail($email);
        }
    }

    private function isZeroTotal(Quote $quote): bool
    {
        $address = $this->totalsAddress($quote);
        return ReproductionLink::isZero(
            (float) $quote->getGrandTotal(),
            (float) $quote->getBaseGrandTotal(),
            (float) $address->getTaxAmount(),
            (float) $address->getBaseTaxAmount()
        );
    }

    private function totalsAddress(Quote $quote): Quote\Address
    {
        return $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();
    }

    /** @return array<int, int> quote item id => original order item id */
    private function sourceItemsByQuoteItemId(Quote $quote): array
    {
        $map = [];
        foreach ($quote->getAllItems() as $item) {
            $option = $item->getOptionByCode(self::SOURCE_ITEM_OPTION);
            if ($option && $item->getId()) {
                $map[(int) $item->getId()] = (int) $option->getValue();
            }
        }
        return $map;
    }

    /**
     * Read-modify-write under a row lock, so concurrent copies of one order do not lose each other's entry.
     *
     * @param list<int> $originalItemIds
     * @return string the new column value
     */
    private function appendReproducedAs(int $originalId, Order $copy, array $originalItemIds): string
    {
        $connection = $this->resource->getConnection('sales');
        $table = $this->resource->getTableName('sales_order', 'sales');
        $connection->beginTransaction();
        try {
            $existing = $connection->fetchOne(
                $connection->select()
                    ->from($table, ['printess_reproduced_as'])
                    ->where('entity_id = ?', $originalId)
                    ->forUpdate(true)
            );
            $json = ReproductionLink::appendReproducedAs(
                is_string($existing) ? $existing : null,
                (int) $copy->getEntityId(),
                (string) $copy->getIncrementId(),
                $originalItemIds,
                (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DATE_ATOM)
            );
            $connection->update($table, ['printess_reproduced_as' => $json], ['entity_id = ?' => $originalId]);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
        return $json;
    }

    /**
     * Double submit guard: refuses when the original lists a copy of exactly these items created less than
     * DOUBLE_SUBMIT_SECONDS ago. Read from the database, not the (possibly cached) order object.
     *
     * @param list<int> $originalItemIds
     * @throws LocalizedException
     */
    private function assertNotJustCopied(int $originalId, array $originalItemIds): void
    {
        $connection = $this->resource->getConnection('sales');
        $existing = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('sales_order', 'sales'), ['printess_reproduced_as'])
                ->where('entity_id = ?', $originalId)
        );
        $wanted = $originalItemIds;
        sort($wanted);
        $now = time();
        foreach (ReproductionLink::parseReproducedAs(is_string($existing) ? $existing : null) as $entry) {
            $ids = $entry['item_ids'];
            sort($ids);
            $created = strtotime($entry['created_at']);
            if ($ids === $wanted && $created !== false && $now - $created < self::DOUBLE_SUBMIT_SECONDS) {
                throw new LocalizedException(__(
                    'These items were just copied as order #%1. Open that order instead of creating another copy.',
                    $entry['increment_id']
                ));
            }
        }
    }

    private function invoice(Order $order): void
    {
        if (!$order->canInvoice()) {
            return; // e.g. "free" configured with a non-pending status already captured it at placement
        }
        $invoice = $this->invoiceService->prepareInvoice($order);
        if (!$invoice->getTotalQty()) {
            throw new LocalizedException(__('The copy has no items to invoice.'));
        }
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $order->setCustomerNoteNotify(false);
        $order->setIsInProcess(true);
        $this->transactionFactory->create()
            ->addObject($invoice)
            ->addObject($order)
            ->save();
    }

    private function addNote(Order $order, string $note): void
    {
        try {
            $history = $order->addCommentToStatusHistory($note, false, false);
            $history->setIsCustomerNotified(false);
            $this->historyRepository->save($history);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not add the note to the order copy', [
                'order_id' => (int) $order->getEntityId(), 'note' => $note, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function problem(string $step, int $copyId, \Throwable $e): string
    {
        $this->logger->error('Printess: order copy created, but could not ' . $step, [
            'copy_order_id' => $copyId, 'error' => $e->getMessage(), 'exception' => $e,
        ]);
        return (string) __('Could not %1: %2', $step, $e->getMessage());
    }

    private function discardQuote(?Quote $quote): void
    {
        if ($quote === null || !$quote->getId()) {
            return;
        }
        try {
            $this->cartRepository->delete($quote);
        } catch (\Throwable $e) {
            $this->logger->warning('Printess: could not delete the quote of a failed order copy', [
                'quote_id' => (int) $quote->getId(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Item prices and totals convert with the store's CURRENT currency; make it the original's order currency for
     * the duration of the copy. Returns the restore callback. `current_currency` is cached on the store, so it is
     * dropped on switching and on restoring.
     *
     * @throws LocalizedException when the store cannot use the original's currency anymore (not allowed, no rate):
     *                            the copy would otherwise mix currencies
     */
    private function switchStoreCurrency(Store $store, string $currencyCode): callable
    {
        $noop = static function (): void {
        };
        if ($currencyCode === '') {
            return $noop;
        }
        $previous = (string) $store->getCurrentCurrencyCode();
        if ($currencyCode === $previous) {
            return $noop;
        }
        $notAllowed = new LocalizedException(__(
            'The copy cannot be created: the order was placed in %1, which the store "%2" does not allow anymore. '
            . 'Allow the currency again (with a rate) and retry.',
            $currencyCode,
            (string) $store->getName()
        ));
        if (!in_array($currencyCode, (array) $store->getAvailableCurrencyCodes(true), true)) {
            throw $notAllowed;
        }

        $restore = static function () use ($store, $previous): void {
            $store->setCurrentCurrencyCode($previous);
            $store->unsetData('current_currency');
        };
        $store->setCurrentCurrencyCode($currencyCode);
        $store->unsetData('current_currency');
        // Without a rate Store::getCurrentCurrency() silently falls back to the base currency.
        if ((string) $store->getCurrentCurrencyCode() !== $currencyCode
            || (string) $store->getCurrentCurrency()->getCode() !== $currencyCode
        ) {
            $restore();
            throw $notAllowed;
        }
        return $restore;
    }
}
