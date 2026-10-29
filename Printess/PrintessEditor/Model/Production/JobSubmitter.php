<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Address as OrderAddress;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\PrintessApiException;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Psr\Log\LoggerInterface;

/**
 * Sends pending jobs to Printess: print jobs one by one (/production/produce), dropship jobs as a GROUP
 * (/dropship/produce, see submitDropshipGroup()).
 *
 * Dropship jobs are marked `submitting` and saved BEFORE the first produce call: if the process dies or the
 * call's outcome is unknown, the job must never be sent again automatically, because a duplicate
 * submission means a duplicate physical shipment. Print jobs keep the legacy retry semantics
 * (fail_count / MAX_FAIL_COUNT) since a duplicate render costs nothing.
 *
 * Every dropship group send of an order runs under one per-order lock (`printess_dropship_send_<orderId>`): the
 * cron skips an order whose lock is held, and admin approve / restart do their state change AND the send inside the
 * lock (sendDropshipGroupLocked()), so a group is never sent twice or split by a concurrent request or cron run.
 *
 * Never throws (except sendDropshipGroupLocked(), see there): the cron must keep processing the other jobs (D9).
 */
class JobSubmitter
{
    private const SAVE_TOKEN_EXPIRED_CODE = 202;

    /** Serialises group sends of one order (cron vs. admin approve/restart). */
    private const GROUP_LOCK_PREFIX  = 'printess_dropship_send_';
    /** Admin requests wait briefly for a running send; the cron never waits (it retries on its next run). */
    private const GROUP_LOCK_TIMEOUT      = 5;
    private const GROUP_LOCK_TIMEOUT_CRON = 0;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly PrintessApiProvider $apiProvider,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly PrintSettingsResolver $printSettingsResolver,
        private readonly ProduceRequestBuilder $requestBuilder,
        private readonly DropshipAddressMapper $addressMapper,
        private readonly OrderStageUpdater $stageUpdater,
        private readonly LoggerInterface $logger,
        private readonly LockManagerInterface $lockManager,
        private readonly DropshipGroupValidator $groupValidator
    ) {
    }

    /**
     * Print job: sends it. Dropship job: sends the pending dropship group of its order (a dropship item is never
     * sent on its own, see submitDropshipGroup()).
     */
    public function submit(PdfJob $job): void
    {
        if ($job->isDropship()) {
            $this->submitDropshipGroup((int) $job->getData('order_id'));
            return;
        }

        try {
            if ((string) $job->getData('status') !== PdfJob::STATUS_PENDING) {
                return;
            }
            $this->doSubmit($job);
        } catch (\Throwable $e) {
            // Anything unexpected before the produce call (DB, order load): safe to retry.
            $this->recordRetryableFailure($job, $e);
        }
    }

    private function doSubmit(PdfJob $job): void
    {
        $orderId = (int) $job->getData('order_id');
        if ($orderId <= 0) {
            // Rows written by early module versions can lack the order id; the repository then throws an
            // InputException ("An ID is needed"), which must not be retried forever. No order to comment on.
            $this->markFailed($job, 'order_missing', 'The job has no Magento order id (legacy row).', false);
            return;
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            $this->markFailed($job, 'order_missing', 'The Magento order no longer exists.', false);
            return;
        }

        $item = $order->getItemById((int) $job->getData('order_item_id'));
        if (!$item instanceof OrderItem) {
            $this->markFailed($job, 'item_missing', 'The order item no longer exists.', true);
            return;
        }

        $storeId = $this->prepareJob($job, $order, $item);

        if (!$this->apiProvider->hasServiceToken($storeId)) {
            // Not an error of this job: it is sent as soon as the store has a token.
            $this->pdfJobRepository->save($job);
            $this->logger->warning('Printess: no service token for store, job left pending', [
                'job_id' => $job->getId(), 'store_id' => $storeId,
            ]);
            return;
        }

        $this->downgradeToPollIfNoCallbackUrl($job, $storeId);

        $callbackBase = (string) $job->getData('callback_mode') === PdfJob::CALLBACK_MODE_CALLBACK
            ? $this->config->getProductionCallbackBaseUrl($storeId)
            : '';
        $ctx = $this->buildContext($order, $storeId, $callbackBase);
        $ctx['order_item_id'] = (int) $job->getData('order_item_id');

        $this->submitPrint($job, $this->apiProvider->forStore($storeId), $ctx);
    }

    /**
     * Fills values that rows created before the dropship upgrade do not have. Returns the store id.
     */
    private function prepareJob(PdfJob $job, Order $order, OrderItem $item): int
    {
        $storeId = $job->getData('store_id');
        if ($storeId === null || $storeId === '') {
            $storeId = (int) $order->getStoreId();
            $job->setData('store_id', $storeId);
            if ((string) $job->getData('production_type') === '') {
                $job->setData('production_type', PdfJob::TYPE_PRINT);
            }
            $job->setData('copies', max(1, (int) $item->getQtyOrdered()));
            if ($job->getData('print_settings') === null) {
                $job->setData('print_settings', $this->printSettingsResolver->resolve($item, (int) $storeId));
            }
        }

        if ((string) $job->getData('nonce') === '') {
            $job->setData('nonce', bin2hex(random_bytes(32)));
        }

        return (int) $storeId;
    }

    /**
     * A callback-mode job without a usable Production Callback URL would be submitted without callback and then
     * wait 24 h for the safety-net poll. Switch it to poll mode (persisted) so polling picks it up promptly.
     * Poll-mode jobs are never switched to callback.
     */
    private function downgradeToPollIfNoCallbackUrl(PdfJob $job, int $storeId): void
    {
        if ((string) $job->getData('callback_mode') !== PdfJob::CALLBACK_MODE_CALLBACK) {
            return;
        }
        if (trim((string) $this->config->getProductionCallbackBaseUrl($storeId)) !== '') {
            return;
        }
        $job->setData('callback_mode', PdfJob::CALLBACK_MODE_POLL);
        $job->appendHistory(['event' => 'callback_mode_poll', 'reason' => 'no_callback_url']);
        $this->pdfJobRepository->save($job);
        $this->logger->info('Printess: no Production Callback URL configured, job switched to poll mode', [
            'job_id' => $job->getId(), 'store_id' => $storeId,
        ]);
    }

    private function buildContext(Order $order, int $storeId, string $callbackBase): array
    {
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();
        $customerName = $address
            ? trim($address->getFirstname() . ' ' . $address->getLastname())
            : trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());

        return [
            'origin'             => $this->origin($storeId),
            'order_increment_id' => (string) $order->getIncrementId(),
            'order_id'           => (int) $order->getId(),
            'order_item_id'      => 0,
            'store_id'           => $storeId,
            'callback_base_url'  => $callbackBase,
            'shipping_code'      => (string) $order->getShippingMethod(),
            'shipping_title'     => (string) $order->getShippingDescription(),
            'customer_name'      => $customerName,
            'dropship_data_id'   => null,
            'linked_count'       => 1,
        ];
    }

    private function submitPrint(PdfJob $job, PrintessApi $api, array $ctx): void
    {
        try {
            $result = $api->productionProduce($this->requestBuilder->buildPrint($job->getData(), $ctx));
        } catch (PrintessApiException $e) {
            if ($e->isPermanent()) {
                $this->failPermanently($job, $e);
            } else {
                $this->recordRetryableFailure($job, $e);
            }
            return;
        }

        $job->setData('submitted_at', gmdate('Y-m-d H:i:s'));
        $this->markProducing($job, $result);
    }

    /**
     * Sends the dropship group of an order: every `pending` dropship job of the order, or only the given ones.
     *
     * Each send (Shopware produceLineItems()):
     *  1. validates every item first (order item, save token, product definition, address) — any problem fails the
     *     WHOLE group and nothing is sent;
     *  2. saves a fresh address record (/dropshipData/save, id must be > 0) — never reuses one of an earlier send;
     *  3. uses a new linkedOrderLineItemsId, a new nonce per item (saved before sending), linkedOrderLineItems =
     *     the number of items in THIS send, and shopOrderId = increment id (send 1) or `<increment id>-R<n>`;
     *  4. marks all items `submitting` (saved) before the first produce call, then produces item by item:
     *     a permanent 4xx fails that item, a transport error / 5xx marks it `outcome_unknown` (never resent
     *     automatically).
     *
     * Non-blocking: when another process holds the order's send lock, the order is skipped (whatever is still
     * pending afterwards is picked up by the next cron run). Never throws.
     *
     * @param int[]|null $jobIds restrict the group to these jobs (still only `pending` dropship jobs of the order)
     */
    public function submitDropshipGroup(int $orderId, ?array $jobIds = null): void
    {
        $lockName = self::GROUP_LOCK_PREFIX . $orderId;
        try {
            if (!$this->lockManager->lock($lockName, self::GROUP_LOCK_TIMEOUT_CRON)) {
                $this->logger->warning('Printess: dropship group of order is being sent elsewhere, skipped', [
                    'order_id' => $orderId,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not lock dropship group', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            return;
        }

        try {
            $this->sendPendingGroup($orderId, $jobIds);
        } finally {
            $this->unlockGroup($lockName);
        }
    }

    /**
     * Admin approve / restart: runs $prepare and the send of the jobs it returns under the order's send lock, so no
     * cron run or concurrent admin request can send (part of) the group in between.
     *
     * $prepare runs inside the lock; it must reload the jobs, re-validate them (throwing a LocalizedException when
     * their state changed, e.g. the group was already approved / restarted / is being sent), move them to `pending`
     * and return their ids. Those jobs are then sent like submitDropshipGroup() does (a send failure is recorded on
     * the jobs, never thrown).
     *
     * @param callable(): int[] $prepare
     * @throws LocalizedException when the lock is held by another sender, or whatever $prepare throws
     */
    public function sendDropshipGroupLocked(int $orderId, callable $prepare): void
    {
        $lockName = self::GROUP_LOCK_PREFIX . $orderId;
        try {
            $locked = $this->lockManager->lock($lockName, self::GROUP_LOCK_TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not lock dropship group', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            throw new LocalizedException(
                __('The dropship group of this order could not be locked for sending: %1', $e->getMessage()),
                $e instanceof \Exception ? $e : null
            );
        }
        if (!$locked) {
            throw new LocalizedException(__(
                'The dropship group of this order is being sent to Printess right now. Nothing was changed; reload '
                . 'the page to see the current state.'
            ));
        }

        try {
            $jobIds = array_values(array_map('intval', (array) $prepare()));
            if ($jobIds !== []) {
                $this->sendPendingGroup($orderId, $jobIds);
            }
        } finally {
            $this->unlockGroup($lockName);
        }
    }

    /**
     * Must be called with the order's send lock held. Never throws.
     *
     * @param int[]|null $jobIds
     */
    private function sendPendingGroup(int $orderId, ?array $jobIds): void
    {
        $jobs = [];
        try {
            // Loaded inside the lock, so a concurrent sender's status changes are seen.
            $jobs = $this->loadPendingGroup($orderId, $jobIds);
            if ($jobs !== []) {
                $this->doSubmitGroup($orderId, $jobs);
            }
        } catch (\Throwable $e) {
            $this->recordGroupRetryableFailure($jobs, $e);
        }
    }

    private function unlockGroup(string $lockName): void
    {
        try {
            $this->lockManager->unlock($lockName);
        } catch (\Throwable) {
            // the lock expires with the connection
        }
    }

    /**
     * @param int[]|null $jobIds
     * @return PdfJob[] ordered by job id
     */
    private function loadPendingGroup(int $orderId, ?array $jobIds): array
    {
        $wanted = $jobIds === null ? null : array_flip(array_map('intval', $jobIds));

        return array_values(array_filter(
            $this->pdfJobRepository->getByOrderId($orderId),
            static fn (PdfJob $job): bool => $job->isDropship()
                && (string) $job->getData('status') === PdfJob::STATUS_PENDING
                && ($wanted === null || isset($wanted[(int) $job->getId()]))
        ));
    }

    /**
     * @param PdfJob[] $jobs non-empty, all pending dropship jobs of the order
     */
    private function doSubmitGroup(int $orderId, array $jobs): void
    {
        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            $this->failGroup($jobs, 'order_missing', 'The Magento order no longer exists.', false);
            return;
        }

        $existingItemIds = [];
        foreach ($jobs as $job) {
            $item = $order->getItemById((int) $job->getData('order_item_id'));
            if ($item instanceof OrderItem) {
                $existingItemIds[] = (int) $job->getData('order_item_id');
                $this->prepareJob($job, $order, $item);
            }
        }

        $firstStore = $jobs[0]->getData('store_id');
        $storeId = $firstStore !== null && $firstStore !== '' ? (int) $firstStore : (int) $order->getStoreId();

        if (!$this->apiProvider->hasServiceToken($storeId)) {
            // Not an error of the group: it is sent as soon as the store has a token.
            foreach ($jobs as $job) {
                $this->pdfJobRepository->save($job);
            }
            $this->logger->warning('Printess: no service token for store, dropship group left pending', [
                'order_id' => $orderId, 'store_id' => $storeId,
            ]);
            return;
        }

        /** @var OrderAddress|null $address */
        $address = $order->getShippingAddress() ?: $order->getBillingAddress();
        $jobRows = array_map(fn (PdfJob $job): array => [
            'job_id'                => (int) $job->getId(),
            'order_item_id'         => (int) $job->getData('order_item_id'),
            'save_token'            => $job->getData('save_token'),
            'product_definition_id' => $job->getData('product_definition_id'),
            'label'                 => $this->sku($order, $job),
        ], $jobs);
        $problems = $this->groupValidator->validate($jobRows, $existingItemIds, (bool) $address);
        if ($problems !== []) {
            $summary = DropshipGroupValidator::summarize($problems);
            $this->failGroup($jobs, $summary['code'], $summary['message'], true);
            return;
        }

        $callbackBase = $this->groupCallbackBase($jobs, $storeId);
        $api = $this->apiProvider->forStore($storeId);

        // Every design must still exist at Printess: a rejected item would leave the others waiting at Printess for
        // a linked item that never arrives (B7). Checked before the address is saved, so nothing is sent on failure.
        $states = [];
        foreach ($jobs as $job) {
            $states[(int) $job->getId()] = $api->getSaveTokenState((string) $job->getData('save_token'));
        }
        $problems = $this->groupValidator->tokenProblems($jobRows, $states);
        if ($problems !== []) {
            $summary = DropshipGroupValidator::summarize($problems);
            $this->failGroup($jobs, $summary['code'], $summary['message'], true);
            return;
        }

        try {
            $dropshipDataId = $this->saveDropshipAddress($order, $address, $api);
        } catch (PrintessApiException $e) {
            if ($e->isPermanent()) {
                $this->failGroup(
                    $jobs,
                    $this->errorCode($e),
                    'Printess could not save the dropship address (/dropshipData/save), so nothing was sent: '
                    . ($e->getPrintessMessage() ?? $e->getMessage()),
                    true
                );
            } else {
                // Nothing was sent yet; saving an address twice is harmless.
                $this->recordGroupRetryableFailure($jobs, $e);
            }
            return;
        }
        if ($dropshipDataId <= 0) {
            $this->failGroup(
                $jobs,
                'no_dropship_data_id',
                sprintf('Printess returned no valid dropship address id (%d), so nothing was sent.', $dropshipDataId),
                true
            );
            return;
        }

        $sendNumber = $this->nextSendNumber($orderId, $jobs);
        $shopOrderId  = DropshipShopOrderId::build((string) $order->getIncrementId(), $sendNumber);
        // New on every send: Printess finds the combined order by this id; a reused one would be counted together
        // with the items of an earlier send.
        $linkedId     = bin2hex(random_bytes(16));
        $callbackType = ProduceRequestBuilder::callbackTypeFor($callbackBase);
        $submittedAt  = gmdate('Y-m-d H:i:s');

        // Idempotency marker: from here on no item of this send is ever sent again automatically.
        try {
            foreach ($jobs as $job) {
                // New per send and stored before sending, so a callback can never arrive before the shop knows it,
                // and callbacks of an earlier send no longer match.
                $job->setData('nonce', bin2hex(random_bytes(32)));
                $job->setData('linked_order_id', $linkedId);
                $job->setData('dropship_data_id', $dropshipDataId);
                $job->setData('dropship_send_count', $sendNumber);
                $job->setData('dropship_shop_order_id', $shopOrderId);
                $job->setData('callback_type', $callbackType);
                $job->setData('status', PdfJob::STATUS_SUBMITTING);
                $job->setData('submitted_at', $submittedAt);
                $job->setData('error_code', null);
                $job->setData('error_message', null);
                $job->appendHistory([
                    'status'           => PdfJob::STATUS_SUBMITTING,
                    'send'             => $sendNumber,
                    'shop_order_id'    => $shopOrderId,
                    'linked_order_id'  => $linkedId,
                    'linked_count'     => count($jobs),
                    'dropship_data_id' => $dropshipDataId,
                    'callback_type'    => $callbackType,
                ]);
                $this->pdfJobRepository->save($job);
            }
        } catch (\Throwable $e) {
            // No produce call was made: the group can be restarted safely.
            $this->failGroup($jobs, 'save_failed', 'The dropship group could not be prepared, nothing was sent: ' . $e->getMessage(), true);
            return;
        }

        $ctx = $this->buildContext($order, $storeId, $callbackBase);
        $ctx['dropship_data_id'] = $dropshipDataId;
        $ctx['linked_order_id']  = $linkedId;
        $ctx['linked_count']     = count($jobs);
        $ctx['shop_order_id']    = $shopOrderId;
        $ctx['dispatch_notice']  = $this->dispatchNotice($order);

        $sent = 0;
        foreach ($jobs as $job) {
            if ($this->produceDropshipItem($job, $order, $api, $ctx)) {
                $sent++;
            }
        }

        $this->logger->info('Printess: dropship group sent', [
            'order_id' => $orderId, 'shop_order_id' => $shopOrderId, 'send' => $sendNumber,
            'items' => count($jobs), 'accepted' => $sent,
        ]);

        $total = count($jobs);
        $this->stageUpdater->update($orderId, $sent === $total
            ? sprintf(
                'Printess: %d dropship item(s) sent to production as dropship order %s%s.',
                $total,
                $shopOrderId,
                $sendNumber > 1 ? sprintf(' (send %d)', $sendNumber) : ''
            )
            : sprintf(
                'Printess: only %d of %d dropship item(s) of dropship order %s were accepted by Printess. Printess '
                . 'waits for all %d items before the order goes to the dropshipper: check the failed items and '
                . 'restart the dropship group.',
                $sent,
                $total,
                $shopOrderId,
                $total
            ));
    }

    /**
     * The next send number of the order's dropship group: 1 + the highest send count of ALL dropship jobs of the
     * order (not only the ones in this send), so a partial send can never reuse an earlier order number.
     *
     * @param PdfJob[] $jobs the jobs of this send (also counted, in case the reload misses them)
     */
    private function nextSendNumber(int $orderId, array $jobs): int
    {
        $counts = array_map(static fn (PdfJob $job): mixed => $job->getData('dropship_send_count'), $jobs);
        foreach ($this->pdfJobRepository->getByOrderId($orderId) as $job) {
            if ($job->isDropship()) {
                $counts[] = $job->getData('dropship_send_count');
            }
        }
        return DropshipShopOrderId::nextSendNumber($counts);
    }

    /**
     * @return bool whether Printess accepted the item
     */
    private function produceDropshipItem(PdfJob $job, Order $order, PrintessApi $api, array $ctx): bool
    {
        $ctx['order_item_id'] = (int) $job->getData('order_item_id');

        $body = $this->requestBuilder->buildDropship($job->getData(), $ctx);
        // The order identifiers as sent, so what left the shop can be compared with what Printess shows. No nonce,
        // address or save token: only ids.
        $this->logger->info('Printess: dropship request', [
            'job_id'                 => $job->getId(),
            'externalOrderId'        => $body['externalOrderId'] ?? null,
            'shopOrderId'            => $body['dropship']['data']['shopOrderId'] ?? null,
            'productDefinitionId'    => $body['dropship']['productDefinitionId'] ?? null,
            'linkedOrderLineItems'   => $body['dropship']['linkedOrderLineItems'] ?? null,
            'linkedOrderLineItemsId' => $body['dropship']['linkedOrderLineItemsId'] ?? null,
            'dropshipDataId'         => $body['dropship']['dropshipDataId'] ?? null,
        ]);

        try {
            $result = $api->dropshipProduce($body);
        } catch (\Throwable $e) {
            if ($e instanceof PrintessApiException && $e->isPermanent()) {
                $this->failPermanently($job, $e);
                return false;
            }
            // Transport error, 5xx or an unusable 2xx: Printess may well have created the order.
            $this->markFailed(
                $job,
                'outcome_unknown',
                'Dropship submission outcome unknown: ' . $e->getMessage(),
                true,
                sprintf(
                    'Printess: the dropship submission of item %s could not be confirmed (%s). It may or may not '
                    . 'have reached Printess. Check the Printess order list (dropship order %s) before restarting, '
                    . 'otherwise the item may be produced and shipped twice.',
                    $this->sku($order, $job),
                    $e->getMessage(),
                    (string) $ctx['shop_order_id']
                )
            );
            return false;
        }

        try {
            $this->markProducing($job, $result, false);
        } catch (\Throwable $e) {
            // Printess has the order; the job stays `submitting` and the stale sweep flags it for the admin.
            $this->logger->error('Printess: dropship item accepted but its job could not be saved; left for the stale sweep', [
                'job_id' => $job->getId(), 'error' => $e->getMessage(), 'printess_job_id' => $result['jobId'] ?? null,
            ]);
        }
        return true;
    }

    /**
     * The group's callback base URL: the configured Production Callback URL when every job of the group is in
     * callback mode, otherwise '' (poll). Callback-mode jobs without a usable URL are switched to poll mode
     * (saved with the `submitting` marker), so the poller picks them up promptly.
     *
     * @param PdfJob[] $jobs
     */
    private function groupCallbackBase(array $jobs, int $storeId): string
    {
        $url = trim((string) $this->config->getProductionCallbackBaseUrl($storeId));
        $allCallback = true;
        foreach ($jobs as $job) {
            if ((string) $job->getData('callback_mode') !== PdfJob::CALLBACK_MODE_CALLBACK) {
                $allCallback = false;
            }
        }
        if ($url !== '' && $allCallback) {
            return $url;
        }

        foreach ($jobs as $job) {
            if ((string) $job->getData('callback_mode') === PdfJob::CALLBACK_MODE_CALLBACK) {
                $job->setData('callback_mode', PdfJob::CALLBACK_MODE_POLL);
                $job->appendHistory([
                    'event'  => 'callback_mode_poll',
                    'reason' => $url === '' ? 'no_callback_url' : 'group_in_poll_mode',
                ]);
            }
        }
        return '';
    }

    /**
     * A fresh address record for every send, so a corrected order address is what the dropshipper gets.
     *
     * @throws PrintessApiException
     */
    private function saveDropshipAddress(Order $order, OrderAddress $address, PrintessApi $api): int
    {
        $json = $this->addressMapper->map([
            'firstname'   => $address->getFirstname(),
            'lastname'    => $address->getLastname(),
            'company'     => $address->getCompany(),
            'street'      => $address->getStreet(),
            'city'        => $address->getCity(),
            'postcode'    => $address->getPostcode(),
            'country_id'  => $address->getCountryId(),
            'region_code' => $address->getRegionCode(),
            'telephone'   => $address->getTelephone(),
        ], (string) $order->getCustomerEmail(), $this->dispatchNotice($order));

        $userId = $order->getCustomerId()
            ? (string) $order->getCustomerId()
            : 'guest-' . (string) $order->getIncrementId();

        return $api->saveDropshipAddress($json, null, $userId);
    }

    private function dispatchNotice(Order $order): string
    {
        return DropshipAddressMapper::dispatchNotice(
            $order->getCustomerNote() !== null ? (string) $order->getCustomerNote() : null,
            $order->getData('printess_reproduction_of')
        );
    }

    /**
     * Fails every job of the group with the same reason; one order comment for the group.
     *
     * @param PdfJob[] $jobs
     */
    private function failGroup(array $jobs, string $code, string $message, bool $notify): void
    {
        $orderId = 0;
        $items = [];
        foreach ($jobs as $job) {
            $orderId = (int) $job->getData('order_id');
            $items[] = '#' . (int) $job->getData('order_item_id');
            $this->markFailed($job, $code, $message, false);
        }

        if ($notify && $orderId > 0) {
            try {
                $this->stageUpdater->update($orderId, sprintf(
                    'Printess: the dropship group (item(s) %s) failed (%s): %s',
                    implode(', ', $items),
                    $code,
                    $message
                ));
            } catch (\Throwable $e) {
                $this->logger->error('Printess: could not update order after group failure', [
                    'order_id' => $orderId, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * A failure before anything was sent: the group stays pending and is retried as a whole by the next cron
     * run; after MAX_FAIL_COUNT attempts the whole group fails.
     *
     * @param PdfJob[] $jobs
     */
    private function recordGroupRetryableFailure(array $jobs, \Throwable $e): void
    {
        if ($jobs === []) {
            $this->logger->error('Printess: dropship group submission failed', ['error' => $e->getMessage()]);
            return;
        }

        try {
            $pending = [];
            foreach ($jobs as $job) {
                if ((string) $job->getData('status') === PdfJob::STATUS_PENDING) {
                    $pending[] = $job;
                } else {
                    // Already marked `submitting`: the order may exist at Printess, so it must not go back to
                    // pending. The stale sweep flags it for the admin.
                    $this->logger->error('Printess: dropship job failed after submission started; left for the stale sweep', [
                        'job_id' => $job->getId(), 'error' => $e->getMessage(),
                    ]);
                }
            }
            if ($pending === []) {
                return;
            }

            $failCount = 1;
            foreach ($pending as $job) {
                $failCount = max($failCount, (int) $job->getData('fail_count') + 1);
            }
            $code = $e instanceof PrintessApiException ? $this->errorCode($e) : 'error';

            if ($failCount >= PdfJob::MAX_FAIL_COUNT) {
                foreach ($pending as $job) {
                    $job->setData('fail_count', $failCount);
                }
                $this->failGroup(
                    $pending,
                    $code,
                    sprintf('Sending the dropship group failed %d times: %s', $failCount, $e->getMessage()),
                    true
                );
                return;
            }

            foreach ($pending as $job) {
                $job->setData('fail_count', $failCount);
                $job->setData('error_code', $code);
                $job->setData('error_message', $e->getMessage());
                $job->appendHistory(['event' => 'retry', 'fail_count' => $failCount, 'error' => $e->getMessage()]);
                $this->pdfJobRepository->save($job);
            }
            $this->logger->warning('Printess: dropship group submission failed (will retry)', [
                'order_id' => $pending[0]->getData('order_id'), 'fail_count' => $failCount, 'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $inner) {
            $this->logger->error('Printess: could not record dropship group failure', [
                'error' => $inner->getMessage(), 'original' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array{jobId: string, orderId: ?string} $result
     */
    private function markProducing(PdfJob $job, array $result, bool $updateStage = true): void
    {
        $job->setData('printess_job_id', $result['jobId']);
        $job->setData('printess_order_id', $result['orderId']);
        $job->setData('status', PdfJob::STATUS_PRODUCING);
        $job->setData('error_code', null);
        $job->setData('error_message', null);
        $job->appendHistory([
            'status'            => PdfJob::STATUS_PRODUCING,
            'printess_job_id'   => $result['jobId'],
            'printess_order_id' => $result['orderId'],
        ]);
        $this->pdfJobRepository->save($job);

        $this->logger->info('Printess: submitted production job', [
            'job_id'          => $job->getId(),
            'order_id'        => $job->getData('order_id'),
            'type'            => $job->getData('production_type'),
            'printess_job_id' => $result['jobId'],
        ]);

        if ($updateStage) {
            $this->stageUpdater->update((int) $job->getData('order_id'));
        }
    }

    private function failPermanently(PdfJob $job, PrintessApiException $e): void
    {
        $code = $e->getPrintessCode();
        $message = $code === self::SAVE_TOKEN_EXPIRED_CODE
            ? "The customer's design (save token) has expired"
            : ($e->getPrintessMessage() ?? $e->getMessage());

        $this->markFailed($job, $this->errorCode($e), $message, true);
    }

    /**
     * Print jobs and everything before a dropship produce call: retried on the next cron run until
     * MAX_FAIL_COUNT is reached.
     */
    private function recordRetryableFailure(PdfJob $job, \Throwable $e): void
    {
        if ($job->isDropship() && (string) $job->getData('status') !== PdfJob::STATUS_PENDING) {
            // Failed after the job was marked `submitting` (e.g. saving the produce result): the order may
            // exist at Printess, so it must not go back to pending. The stale sweep flags it for the admin.
            $this->logger->error('Printess: dropship job failed after submission started; left for the stale sweep', [
                'job_id' => $job->getId(), 'error' => $e->getMessage(),
            ]);
            return;
        }

        try {
            $failCount = (int) $job->getData('fail_count') + 1;
            $code      = $e instanceof PrintessApiException ? $this->errorCode($e) : 'error';

            if ($failCount >= PdfJob::MAX_FAIL_COUNT) {
                $job->setData('fail_count', $failCount);
                $this->markFailed(
                    $job,
                    $code,
                    $e->getMessage(),
                    true,
                    sprintf('Printess: production of item %s failed after %d attempts: %s',
                        $this->itemLabel($job), $failCount, $e->getMessage())
                );
                return;
            }

            $job->setData('fail_count', $failCount);
            $job->setData('status', PdfJob::STATUS_PENDING);
            $job->setData('error_code', $code);
            $job->setData('error_message', $e->getMessage());
            $job->appendHistory(['event' => 'retry', 'fail_count' => $failCount, 'error' => $e->getMessage()]);
            $this->pdfJobRepository->save($job);

            $this->logger->warning('Printess: job submission failed (will retry)', [
                'job_id' => $job->getId(), 'fail_count' => $failCount, 'error' => $e->getMessage(),
            ]);
        } catch (\Throwable $inner) {
            $this->logger->error('Printess: could not record job failure', [
                'job_id' => $job->getId(), 'error' => $inner->getMessage(), 'original' => $e->getMessage(),
            ]);
        }
    }

    private function markFailed(PdfJob $job, string $code, string $message, bool $notify, ?string $comment = null): void
    {
        try {
            $job->setData('status', PdfJob::STATUS_FAILED);
            $job->setData('error_code', substr($code, 0, 32));
            $job->setData('error_message', $message);
            $job->appendHistory(['status' => PdfJob::STATUS_FAILED, 'error_code' => $code, 'error' => $message]);
            $this->pdfJobRepository->save($job);
        } catch (\Throwable $e) {
            $this->logger->error('Printess: could not save failed job', [
                'job_id' => $job->getId(), 'error' => $e->getMessage(),
            ]);
        }

        $this->logger->error('Printess: production job failed', [
            'job_id' => $job->getId(), 'order_id' => $job->getData('order_id'), 'code' => $code, 'error' => $message,
        ]);

        if ($notify) {
            $this->stageUpdater->update(
                (int) $job->getData('order_id'),
                $comment ?? sprintf('Printess: production of item %s failed (%s): %s',
                    $this->itemLabel($job), $code, $message)
            );
        }
    }

    private function errorCode(PrintessApiException $e): string
    {
        if ($e->getPrintessCode() !== null) {
            return 'printess_' . $e->getPrintessCode();
        }
        if ($e->getHttpStatus() !== null) {
            return 'http_' . $e->getHttpStatus();
        }
        return $e->isTransportError() ? 'transport_error' : 'error';
    }

    private function origin(int $storeId): string
    {
        try {
            return 'Magento ' . $this->storeManager->getStore($storeId)->getBaseUrl();
        } catch (\Throwable) {
            return 'Magento';
        }
    }

    /**
     * The item's SKU for order comments (as the other Printess comments name items), or "#<item id>" when the order
     * or item can no longer be loaded. Never throws.
     */
    private function itemLabel(PdfJob $job): string
    {
        $itemId = (int) $job->getData('order_item_id');
        try {
            $orderId = (int) $job->getData('order_id');
            if ($orderId > 0) {
                /** @var Order $order */
                $order = $this->orderRepository->get($orderId);
                $sku = (string) $order->getItemById($itemId)?->getSku();
                if ($sku !== '') {
                    return $sku;
                }
            }
        } catch (\Throwable) {
            // fall through to the id
        }
        return '#' . $itemId;
    }

    private function sku(Order $order, PdfJob $job): string
    {
        $item = $order->getItemById((int) $job->getData('order_item_id'));
        return $item ? (string) $item->getSku() : '#' . (int) $job->getData('order_item_id');
    }
}
