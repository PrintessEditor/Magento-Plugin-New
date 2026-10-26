<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Adminhtml\Order\View\Tab;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Tab\TabInterface;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Printess\PrintessEditor\Model\Config\Source\DropshipProductDefinitions;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\ReproductionLink;
use Printess\PrintessEditor\Model\Production\RetryService;
use Printess\PrintessEditor\Model\Production\ProductionResultFiles;
use Printess\PrintessEditor\Model\Production\TrackingHistory;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\Collection as PdfJobCollection;
use Printess\PrintessEditor\Model\ResourceModel\PdfJob\CollectionFactory as PdfJobCollectionFactory;

/**
 * "Printess Production" tab on the admin order view: per-item production state plus the manual actions
 * (approve, check status, retry / resubmit for print items, restart for dropship groups). All decisions about which action is allowed are made here
 * (and re-checked server-side), so the template only renders prepared view data.
 */
class Production extends Template implements TabInterface
{
    private const HISTORY_LIMIT = 10;

    private const STATUS_LABELS = [
        PdfJob::STATUS_AWAITING_PAYMENT  => 'Awaiting payment',
        PdfJob::STATUS_AWAITING_APPROVAL => 'Awaiting approval',
        PdfJob::STATUS_PENDING           => 'Queued',
        PdfJob::STATUS_SUBMITTING        => 'Submitting',
        PdfJob::STATUS_PRODUCING         => 'Rendering',
        PdfJob::STATUS_RENDERED          => 'Rendered, handed to dropshipper',
        PdfJob::STATUS_SHIPPED           => 'Shipped',
        PdfJob::STATUS_COMPLETE          => 'Complete',
        PdfJob::STATUS_FAILED            => 'Failed',
    ];

    private const PARTNER_STATE_LABELS = [
        0  => 'Submitted',
        10 => 'Received by dropshipper',
        11 => 'Print-ready',
        1  => 'Shipped',
        98 => 'Cancelled',
        99 => 'Error',
    ];

    /** @var string */
    protected $_template = 'Printess_PrintessEditor::order/production.phtml';

    /** @var PdfJob[]|null */
    private ?array $jobs = null;

    /** @var array<int, array<string, mixed>>|null */
    private ?array $reproductions = null;

    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly DropshipProductDefinitions $productDefinitions,
        private readonly RetryService $retryService,
        private readonly OrderItemCollectionFactory $orderItemCollectionFactory,
        private readonly PdfJobCollectionFactory $pdfJobCollectionFactory,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getTabLabel()
    {
        return __('Printess Production');
    }

    public function getTabTitle()
    {
        return __('Printess Production');
    }

    /** Shown for orders with Printess jobs, and for originals whose copies are listed (PMD-25). */
    public function canShowTab()
    {
        return $this->getJobs() !== []
            || ReproductionLink::parseReproducedAs($this->getOrder()?->getData('printess_reproduced_as')) !== [];
    }

    public function isHidden()
    {
        return false;
    }

    public function getOrder(): ?Order
    {
        $order = $this->registry->registry('current_order');
        return $order instanceof Order ? $order : null;
    }

    /**
     * The order this order is a copy of (sales_order.printess_reproduction_of, PMD-21), or null.
     *
     * `message` is the merchant's message to the customer: a `message` stored in the link, otherwise the copy's
     * discount title (the reproduction discount is titled with it; cut to the column length).
     *
     * @return array{increment_id: string, url: string, message: string}|null
     */
    public function getReproductionOf(): ?array
    {
        $order = $this->getOrder();
        $data = $this->decodeJson($order?->getData('printess_reproduction_of'));
        if ($data === null || array_is_list($data)) {
            return null;
        }
        $link = $this->orderLink($data);
        if ($link === null) {
            return null;
        }
        $message = '';
        foreach (['message', 'customer_message'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                $message = trim((string) $data[$key]);
                break;
            }
        }
        if ($message === '') {
            $message = trim((string) $order?->getData('discount_description'));
        }
        return $link + ['message' => $message];
    }

    /**
     * The copies of this order with their Printess items (PMD-25), oldest first. One job query and one order item
     * query (for the names) for all copies together.
     *
     * @return list<array{increment_id: string, url: string, created_at: string,
     *     items: list<array{name: string, status_label: string, shop_order_id: string, send_count: int,
     *     tracking_id: string, tracking_url: string, shipped_at: string}>}>
     */
    public function getReproductions(): array
    {
        if ($this->reproductions !== null) {
            return $this->reproductions;
        }
        $copies = ReproductionLink::parseReproducedAs($this->getOrder()?->getData('printess_reproduced_as'));

        $jobsByCopy = $this->jobsByOrderIds(array_column($copies, 'id'));
        $itemIds = [];
        foreach ($jobsByCopy as $jobs) {
            foreach ($jobs as $job) {
                $itemIds[] = (int) $job->getData('order_item_id');
            }
        }
        $names = $this->itemNames($itemIds);

        $this->reproductions = [];
        foreach ($copies as $copy) {
            $items = [];
            foreach ($jobsByCopy[(int) $copy['id']] ?? [] as $job) {
                $itemId = (int) $job->getData('order_item_id');
                $latest = $this->latestTracking($job);
                $items[] = [
                    'name'          => $names[$itemId] ?? (string) __('Order item #%1', $itemId),
                    'status_label'  => $this->statusLabel((string) $job->getData('status')),
                    'shop_order_id' => $job->isDropship() ? (string) $job->getData('dropship_shop_order_id') : '',
                    'send_count'    => (int) $job->getData('dropship_send_count'),
                    'tracking_id'   => $latest['tracking_id'],
                    'tracking_url'  => $latest['tracking_url'],
                    'shipped_at'    => $this->localDate($latest['shipped_at']),
                ];
            }
            $this->reproductions[] = [
                'increment_id' => $copy['increment_id'] !== '' ? $copy['increment_id'] : (string) $copy['id'],
                'url'          => $this->getUrl('sales/order/view', ['order_id' => $copy['id']]),
                'created_at'   => $this->localDate($copy['created_at']),
                'items'        => $items,
            ];
        }
        return $this->reproductions;
    }

    /**
     * The Printess jobs of several orders with one query, grouped by order id, oldest job first.
     *
     * @param array<int|string> $orderIds
     * @return array<int, list<PdfJob>> order id => jobs
     */
    private function jobsByOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(
            array_map('intval', $orderIds),
            static fn (int $id) => $id > 0
        )));
        if ($orderIds === []) {
            return [];
        }
        $collection = $this->pdfJobCollectionFactory->create();
        $collection->addFieldToFilter('order_id', ['in' => $orderIds]);
        $collection->setOrder('job_id', PdfJobCollection::SORT_ORDER_ASC);
        $grouped = [];
        foreach ($collection as $job) {
            $grouped[(int) $job->getData('order_id')][] = $job;
        }
        return $grouped;
    }

    /**
     * @param list<int> $itemIds
     * @return array<int, string> item id => name
     */
    private function itemNames(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter($itemIds, static fn (int $id) => $id > 0)));
        if ($itemIds === []) {
            return [];
        }
        $names = [];
        try {
            $collection = $this->orderItemCollectionFactory->create();
            $collection->addFieldToSelect(['item_id', 'name'])->addFieldToFilter('item_id', ['in' => $itemIds]);
            foreach ($collection as $item) {
                $names[(int) $item->getId()] = (string) $item->getName();
            }
        } catch (\Throwable) {
            return [];
        }
        return $names;
    }

    /**
     * The newest shipment of a job: the last tracking_json entry, else the legacy single tracking columns.
     *
     * @return array{tracking_id: string, tracking_url: string, shipped_at: string}
     */
    private function latestTracking(PdfJob $job): array
    {
        $rows = TrackingHistory::rows(TrackingHistory::decodeList($job->getData('tracking_json')), [], '', 0, '');
        if ($rows !== []) {
            return [
                'tracking_id'  => $rows[0]['tracking_id'],
                'tracking_url' => $rows[0]['tracking_url'],
                'shipped_at'   => $rows[0]['shipped_at'],
            ];
        }
        return [
            'tracking_id'  => (string) $job->getData('tracking_id'),
            'tracking_url' => $this->safeUrl((string) $job->getData('tracking_url')),
            'shipped_at'   => (string) $job->getData('shipped_at'),
        ];
    }

    /** A stored UTC / ISO date in the admin locale and timezone; unparsable values are returned as stored. */
    private function localDate(string $date): string
    {
        if (trim($date) === '') {
            return '';
        }
        try {
            return (string) $this->formatDate(new \DateTime($date), \IntlDateFormatter::MEDIUM, true);
        } catch (\Throwable) {
            return $date;
        }
    }

    private function statusLabel(string $status): string
    {
        return (string) __(self::STATUS_LABELS[$status] ?? $status);
    }

    /**
     * @param array<string, mixed> $data {id, increment_id, ...}
     * @return array{increment_id: string, url: string}|null url '' when the id is unknown
     */
    private function orderLink(array $data): ?array
    {
        $id = isset($data['id']) && is_numeric($data['id']) ? (int) $data['id'] : 0;
        $incrementId = isset($data['increment_id']) && is_scalar($data['increment_id'])
            ? trim((string) $data['increment_id'])
            : '';
        if ($id <= 0 && $incrementId === '') {
            return null;
        }
        return [
            'increment_id' => $incrementId !== '' ? $incrementId : (string) $id,
            'url'          => $id > 0 ? $this->getUrl('sales/order/view', ['order_id' => $id]) : '',
        ];
    }

    private function decodeJson(mixed $json): ?array
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @return array<int, array<string, mixed>> one entry per Printess job of the order
     */
    public function getJobViews(): array
    {
        $order = $this->getOrder();
        $views = [];
        foreach ($this->getJobs() as $job) {
            $views[] = $this->buildView($job, $order);
        }
        return $views;
    }

    /**
     * @return PdfJob[]
     */
    private function getJobs(): array
    {
        if ($this->jobs === null) {
            $order = $this->getOrder();
            $this->jobs = $order && $order->getId()
                ? $this->pdfJobRepository->getByOrderId((int) $order->getId())
                : [];
        }
        return $this->jobs;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildView(PdfJob $job, ?Order $order): array
    {
        $itemId = (int) $job->getData('order_item_id');
        $item = $order?->getItemById($itemId);
        $status = (string) $job->getData('status');
        $partnerState = $job->getData('dropship_state');
        $partnerState = $partnerState !== null && $partnerState !== '' ? (int) $partnerState : null;
        $printessOrderId = (string) $job->getData('printess_order_id');

        $retryBlockReason = null;
        if ($printessOrderId !== '') {
            $retryBlockReason = $this->retryService->getRetryBlockReason($job);
        }

        return [
            'job_id'              => (int) $job->getId(),
            'item_name'           => $item ? (string) $item->getName() : (string) __('Order item #%1', $itemId),
            'sku'                 => $item ? (string) $item->getSku() : '',
            'save_token'          => (string) $job->getData('save_token'),
            'copies'              => (int) $job->getData('copies'),
            'is_dropship'         => $job->isDropship(),
            'type_label'          => $this->typeLabel($job),
            'status'              => $status,
            'status_label'        => $this->statusLabel($status),
            'printess_job_id'     => (string) $job->getData('printess_job_id'),
            'printess_order_id'   => $printessOrderId,
            'submitted_at'        => (string) $job->getData('submitted_at'),
            'shop_order_id'       => $job->isDropship() ? (string) $job->getData('dropship_shop_order_id') : '',
            'send_count'          => (int) $job->getData('dropship_send_count'),
            'partner_state'       => $partnerState,
            'partner_state_label' => $partnerState === null
                ? ''
                : (string) __(self::PARTNER_STATE_LABELS[$partnerState] ?? 'Unknown (%1)', $partnerState),
            'tracking_id'         => (string) $job->getData('tracking_id'),
            'tracking_url'        => $this->safeUrl((string) $job->getData('tracking_url')),
            'shipped_at'          => (string) $job->getData('shipped_at'),
            'tracking_history'    => $this->trackingHistory($job, $order),
            'files'               => $this->resultFiles((string) $job->getData('result_json')),
            'pdf_path'            => $job->isDropship() ? '' : (string) $job->getData('pdf_path'),
            'error_code'          => (string) $job->getData('error_code'),
            'error_message'       => (string) $job->getData('error_message'),
            'history'             => $this->history((string) $job->getData('history_json')),
            'retry_block_reason'  => $retryBlockReason,
            'actions'             => $this->actions($job, $retryBlockReason),
        ];
    }

    /**
     * Every shipment of the item (tracking_json), newest first, with the dropship order number of its send
     * (PMD-25). Empty for jobs without tracking_json; the template then shows the legacy single tracking row.
     *
     * @return list<array{send: int, shop_order_id: string, tracking_id: string, tracking_url: string, shipped_at: string}>
     */
    private function trackingHistory(PdfJob $job, ?Order $order): array
    {
        $rows = TrackingHistory::rows(
            TrackingHistory::decodeList($job->getData('tracking_json')),
            TrackingHistory::decodeList($job->getData('history_json')),
            (string) $order?->getIncrementId(),
            (int) $job->getData('dropship_send_count'),
            (string) $job->getData('dropship_shop_order_id')
        );
        foreach ($rows as &$row) {
            $row['shipped_at'] = $this->localDate($row['shipped_at']);
        }
        unset($row);
        return $rows;
    }

    private function typeLabel(PdfJob $job): string
    {
        if (!$job->isDropship()) {
            return (string) __('Print');
        }
        $definitionId = $job->getData('product_definition_id');
        if ($definitionId === null || $definitionId === '') {
            return (string) __('Dropship');
        }
        $definitionId = (int) $definitionId;
        if ($definitionId === 0) {
            return (string) __('Dropship: from Printess template');
        }
        // Resolve with the job's store so a store-/website-level token is used.
        $storeId = $job->getData('store_id');
        $label = $this->productDefinitions->getDefinitionLabel(
            $definitionId,
            $storeId !== null && $storeId !== '' ? (int) $storeId : null
        );
        return (string) __('Dropship: %1', $label ?? ('#' . $definitionId));
    }

    /**
     * Allowed actions in display order. Each: key, label, url, method (post = form with form key, get = link),
     * confirm (null = none), params (hidden inputs).
     *
     * Dropship items are approved and restarted as a group on the batch page (printess/production/batch), which
     * shows the items sent together and the order number the dropshipper receives. /order/retry is not offered for
     * them (it reuses the linked id and order number), except "Re-render without dropshipping".
     *
     * @return array<int, array<string, mixed>>
     */
    private function actions(PdfJob $job, ?string $retryBlockReason): array
    {
        $jobId = (int) $job->getId();
        $status = (string) $job->getData('status');
        $isDropship = $job->isDropship();
        $actions = [];

        if ($status === PdfJob::STATUS_AWAITING_APPROVAL) {
            $actions[] = $isDropship
                ? $this->batchLink('approve', __('Approve for production'), $jobId)
                : $this->action('approve', __('Approve for production'), $jobId, __('Release this item for production?'));
        }

        if ((string) $job->getData('printess_job_id') !== ''
            || ($isDropship && (string) $job->getData('printess_order_id') !== '')
        ) {
            $actions[] = $this->action('checkStatus', __('Check status'), $jobId, null);
        }

        if ($isDropship && $this->retryService->getResendBlockReason($job) === null) {
            $actions[] = $this->batchLink('restart', __('Restart production'), $jobId);
        }

        if ((string) $job->getData('printess_order_id') !== '' && $retryBlockReason === null) {
            if ($isDropship) {
                $actions[] = $this->action('retry', __('Re-render without dropshipping'), $jobId, __(
                    'This renders the files again under a new Printess order WITHOUT dropshipping: you fulfil the item '
                    . 'yourself and it is no longer tracked as a dropship item. If the first submission already reached '
                    . 'the dropshipper, cancel it there, otherwise the item may be shipped anyway. Continue?'
                ), ['mode' => RetryService::MODE_CLEAR_DROPSHIP]);
            } else {
                $actions[] = $this->action('retry', __('Retry production'), $jobId,
                    __('This renders the item again under a new Printess order. Continue?'),
                    ['mode' => RetryService::MODE_DEFAULT]);

                if ($this->isPaymentError($job)) {
                    $actions[] = $this->action('retry', __('Retry with payment reload'), $jobId,
                        __('Printess reloads the payment data of your account and submits the item again under a new Printess order.')
                        . ' ' . __('Continue?'),
                        ['mode' => RetryService::MODE_RELOAD_PAYMENT]);
                }
            }
        }

        if (!$isDropship && $this->retryService->getResubmitBlockReason($job) === null) {
            if ($this->retryService->isOutcomeUnknown($job)) {
                $actions[] = $this->action('resubmit', __('Resubmit anyway'), $jobId, __(
                    'The previous submission may have reached Printess. Check the Printess order list (external order id %1) '
                    . 'first: if an order exists there, resubmitting renders the item a second time. Resubmit anyway?',
                    (string) $this->getOrder()?->getId()
                ), ['confirm_unknown' => '1']);
            } else {
                $actions[] = $this->action('resubmit', __('Resubmit'), $jobId, __(
                    'No Printess order exists for this item. Queue it for a new submission to Printess?'
                ));
            }
        }

        return $actions;
    }

    private function action(string $key, mixed $label, int $jobId, mixed $confirm, array $params = []): array
    {
        return [
            'key'     => $key,
            'label'   => (string) $label,
            'url'     => $this->getUrl('printess/production/' . strtolower($key), ['job_id' => $jobId]),
            'method'  => 'post',
            'confirm' => $confirm === null ? null : (string) $confirm,
            'params'  => $params,
        ];
    }

    /** GET link to the Approve / Restart page of a dropship group ($batchAction: approve | restart). */
    private function batchLink(string $batchAction, mixed $label, int $jobId): array
    {
        return [
            'key'     => 'batch_' . $batchAction,
            'label'   => (string) $label,
            'url'     => $this->getUrl('printess/production/batch', ['job_id' => $jobId, 'action' => $batchAction]),
            'method'  => 'get',
            'confirm' => null,
            'params'  => [],
        ];
    }

    private function isPaymentError(PdfJob $job): bool
    {
        return str_starts_with((string) $job->getData('error_code'), 'http_402')
            || stripos((string) $job->getData('error_message'), 'payment') !== false;
    }

    /**
     * Rendering result files (PDFs, images, ZIP; see ProductionResultFiles), label => URL. Only http(s) links are returned.
     *
     * @return array<string, string>
     */
    private function resultFiles(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }
        // result_json holds the `result` object; tolerate a stored full payload too.
        $files = ProductionResultFiles::fromResult(isset($data['result']) && is_array($data['result']) ? $data['result'] : $data);
        $labels = ProductionResultFiles::labels($files);
        $links = [];
        foreach ($files as $n => $file) {
            $url = $this->safeUrl($file['url']);
            if ($url !== '') {
                $links[$labels[$n]] = $url;
            }
        }
        return $links;
    }

    /**
     * Newest first, at most HISTORY_LIMIT entries.
     *
     * @return array<int, array{at: string, text: string}>
     */
    private function history(string $json): array
    {
        if ($json === '') {
            return [];
        }
        $history = json_decode($json, true);
        if (!is_array($history) || !array_is_list($history)) {
            return [];
        }
        $entries = [];
        foreach (array_reverse(array_slice($history, -self::HISTORY_LIMIT)) as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $parts = [];
            foreach ($entry as $key => $value) {
                if ($key === 'at' || $key === 'nonce' || $value === null || $value === '') {
                    continue;
                }
                $parts[] = $key . ': ' . (is_scalar($value)
                    ? (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value)
                    : (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
            $entries[] = ['at' => (string) ($entry['at'] ?? ''), 'text' => implode(', ', $parts)];
        }
        return $entries;
    }

    private function safeUrl(string $url): string
    {
        return TrackingHistory::safeUrl($url);
    }
}
