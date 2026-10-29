<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Dropship;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Printess\PrintessEditor\Model\PdfJob;
use Printess\PrintessEditor\Model\PdfJobRepository;
use Printess\PrintessEditor\Model\Production\CallbackVerifier;
use Printess\PrintessEditor\Model\Production\DropshipCallbackParser;
use Printess\PrintessEditor\Model\Production\DropshipShipmentService;
use Printess\PrintessEditor\Model\Production\OrderStageUpdater;
use Psr\Log\LoggerInterface;

/**
 * Dropship tracking callbacks from Printess.
 *
 * POST printess/dropship/callback?order=<order entity id> — dropship.callbackType 3:
 *   body `{orderId, orderStatus, lineItems: [{lineItemId, nonce, trackingId, trackingUrl}]}`. Each entry is matched
 *   by its nonce (constant time) against the CURRENT nonce of that order's dropship jobs; Printess's own ids are
 *   ignored. All matched items go to DropshipShipmentService::markShippedBatch (one shipment, de-duplicated).
 *   400 bad request, 404 unknown order, 403 no entry matched, 200 `{status: ok, updated: n}`.
 *
 * POST printess/dropship/callback?n=<nonce> — legacy dropship.callbackType 1, only for jobs sent before the
 *   upgrade. The nonce in the URL identifies the job; the echoed orderId/lineItemId (and printessOrderId) must
 *   match it. Once verified we always answer 200 for business outcomes so Printess stops retrying.
 *
 * Unexpected failures (e.g. DB down, order locked) answer 500 so the retry can deliver the update later.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const NONCE_PARAM   = 'n';
    private const ORDER_PARAM   = 'order';
    private const MAX_NONCE_LEN = 128;

    /** Partner states from /dropship/orders/list: 98 cancelled, 99 error. */
    private const PARTNER_FAILURE_STATES = [98 => 'cancelled', 99 => 'error'];

    /** trackingId placeholder while a combined (linked) order is only partly shipped. */
    private const PARTIAL_TRACKING_ID = 'partial';

    /** Job statuses after which partner failure states are only recorded, never applied. */
    private const FINAL_SHIPPED_STATUSES = [PdfJob::STATUS_SHIPPED, PdfJob::STATUS_COMPLETE];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly CallbackVerifier $verifier,
        private readonly DropshipShipmentService $shipmentService,
        private readonly OrderStageUpdater $orderStageUpdater,
        private readonly LoggerInterface $logger,
        private readonly DropshipCallbackParser $callbackParser
    ) {
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /** Caller is Printess (no form key); authenticity comes from the nonce. */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    public function execute(): Json
    {
        try {
            $nonce = $this->request->getQuery(self::NONCE_PARAM);
            if ($nonce === null) {
                return $this->executeNonceCallback();
            }

            $job   = $this->findJob(is_string($nonce) ? trim($nonce) : '');
            if ($job === null) {
                return $this->respond(404, 'not_found');
            }

            $payload = json_decode((string) $this->request->getContent(), true);
            if (!is_array($payload)) {
                $this->logger->warning(sprintf(
                    'Printess dropship callback for job %d: body is not a JSON object.',
                    (int) $job->getId()
                ));
                return $this->respond(400, 'bad_request');
            }

            if (!$this->verifier->verifyShipped($job->getData(), $payload)) {
                $this->logger->warning(sprintf(
                    'Printess dropship callback rejected for job %d (order %d, item %d): order/item/Printess order id mismatch.',
                    (int) $job->getId(),
                    (int) $job->getData('order_id'),
                    (int) $job->getData('order_item_id')
                ));
                return $this->respond(403, 'forbidden');
            }

            $this->handle($job, $payload);
            return $this->respond(200, 'ok');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Printess dropship callback failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return $this->respond(500, 'error');
        }
    }

    /** callbackType 3: `?order=<id>`, one nonce per line item in the body. */
    private function executeNonceCallback(): Json
    {
        $orderParam = $this->request->getQuery(self::ORDER_PARAM);
        $orderParam = is_string($orderParam) ? trim($orderParam) : '';
        if (!preg_match('/^[1-9]\d{0,9}$/', $orderParam)) {
            return $this->respond(400, 'bad_request');
        }
        $orderId = (int) $orderParam;

        $payload = json_decode((string) $this->request->getContent(), true);
        if (!is_array($payload) || !is_array($payload['lineItems'] ?? null)) {
            $this->logger->warning(sprintf(
                'Printess dropship callback for order %d: body is not a JSON object with lineItems.',
                $orderId
            ));
            return $this->respond(400, 'bad_request');
        }

        $jobs = $this->pdfJobRepository->getByOrderId($orderId);
        if ($jobs === []) {
            return $this->respond(404, 'not_found');
        }

        $items   = [];
        $matched = [];
        foreach ($this->callbackParser->parse($payload) as $entry) {
            if (strlen($entry['nonce']) > self::MAX_NONCE_LEN) {
                continue;
            }
            $job = $this->matchJob($jobs, $entry['nonce']);
            if ($job === null) {
                continue;
            }
            $items[] = [
                'job'          => $job,
                'tracking_id'  => $entry['tracking_id'],
                'tracking_url' => $entry['tracking_url'] !== '' ? $entry['tracking_url'] : null,
            ];
            $matched[(int) $job->getId()] = true;
        }

        if ($items === []) {
            $this->logger->warning(sprintf(
                'Printess dropship callback rejected for order %d: no line item matches a current nonce.',
                $orderId
            ));
            return $this->respond(403, 'forbidden');
        }

        // Saves the jobs (tracking list, status), creates/extends shipments and updates the order stage.
        $this->shipmentService->markShippedBatch($orderId, $items);

        return $this->respond(200, 'ok', ['updated' => count($matched)]);
    }

    /**
     * The dropship job of the order whose current nonce is exactly $nonce (constant-time compare).
     *
     * @param PdfJob[] $jobs
     */
    private function matchJob(array $jobs, string $nonce): ?PdfJob
    {
        foreach ($jobs as $job) {
            if (!$job->isDropship()) {
                continue;
            }
            $stored = (string) $job->getData('nonce');
            if ($stored !== '' && hash_equals($stored, $nonce)) {
                return $job;
            }
        }
        return null;
    }

    private function handle(PdfJob $job, array $payload): void
    {
        $orderId = (int) $job->getData('order_id');
        $state   = $this->intOrNull($payload['state'] ?? null);
        $event   = is_string($payload['eventName'] ?? null) ? trim($payload['eventName']) : '';

        $trackingId = is_scalar($payload['trackingId'] ?? null) ? trim((string) $payload['trackingId']) : '';
        // A combined (linked) order reports trackingId "partial" while only some parcels left: that is progress,
        // never a shipment (same rule as DropshipOrderStateMapper for the poller).
        $isPartial = strcasecmp($trackingId, self::PARTIAL_TRACKING_ID) === 0;
        if ($isPartial) {
            $trackingId = '';
        }

        $isFailure = $state !== null && isset(self::PARTNER_FAILURE_STATES[$state]);
        if ($isFailure && in_array((string) $job->getData('status'), self::FINAL_SHIPPED_STATUSES, true)) {
            // Already shipped: a late cancel/error must not turn the job (and order stage) back to failed.
            $this->logger->warning(sprintf(
                'Printess dropship callback for job %d: partner state %d ignored, job is already %s.',
                (int) $job->getId(),
                $state,
                (string) $job->getData('status')
            ));
            $job->appendHistory([
                'event'   => 'dropship_callback_ignored',
                'state'   => $state,
                'reason'  => 'already_shipped',
                'message' => $this->str($payload['errorMessage'] ?? null) ?: null,
            ]);
            $this->pdfJobRepository->save($job);
            return;
        }

        if ($state !== null) {
            $job->setData('dropship_state', $state);
        }

        if ($isFailure) {
            $message = $this->str($payload['errorMessage'] ?? null)
                ?: $this->str($payload['message'] ?? null)
                ?: sprintf('Dropship partner reported state %d (%s).', $state, self::PARTNER_FAILURE_STATES[$state]);

            $job->setData('status', PdfJob::STATUS_FAILED);
            $job->setData('error_code', 'partner_' . $state);
            $job->setData('error_message', $message);
            $job->appendHistory([
                'event'   => 'dropship_callback',
                'status'  => PdfJob::STATUS_FAILED,
                'state'   => $state,
                'message' => $message,
            ]);
            $this->pdfJobRepository->save($job);

            $this->orderStageUpdater->update(
                $orderId,
                sprintf(
                    'Printess dropship: item %d %s by dropshipper (state %d): %s',
                    (int) $job->getData('order_item_id'),
                    self::PARTNER_FAILURE_STATES[$state],
                    $state,
                    $message
                )
            );
            return;
        }

        if (!$isPartial && (strcasecmp($event, 'shipped') === 0 || $trackingId !== '')) {
            // markShipped saves the job (incl. the dropship_state set above) and updates the order stage.
            $this->shipmentService->markShipped(
                $job,
                $trackingId,
                $this->str($payload['trackingPayload'] ?? null) ?: null,
                $this->str($payload['trackingType'] ?? null) ?: null
            );
            return;
        }

        // Intermediate partner state (e.g. 10 received, 11 print-ready): record it, the stage may advance.
        $job->appendHistory([
            'event'      => 'dropship_callback',
            'state'      => $state,
            'event_name' => $event !== '' ? $event : null,
            'partial'    => $isPartial ? true : null,
        ]);
        $this->pdfJobRepository->save($job);
        $this->orderStageUpdater->update($orderId);
    }

    private function findJob(string $nonce): ?PdfJob
    {
        if ($nonce === '' || strlen($nonce) > self::MAX_NONCE_LEN) {
            return null;
        }
        $job = $this->pdfJobRepository->getByNonce($nonce);
        if ($job === null) {
            return null;
        }
        // The DB lookup may be case-insensitive (collation); require an exact, constant-time match.
        $stored = (string) $job->getData('nonce');
        if ($stored === '' || !hash_equals($stored, $nonce)) {
            return null;
        }
        // A job sent with callbackType 3 never had its nonce in a URL; it is only reachable via ?order=.
        $type = $job->getData('callback_type');
        if ($type !== null && $type !== '' && (int) $type === PdfJob::DROPSHIP_CALLBACK_TYPE_NONCES) {
            return null;
        }
        return $job;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
            return (int) trim($value);
        }
        if (is_float($value)) {
            return (int) $value;
        }
        return null;
    }

    private function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function respond(int $httpCode, string $status, array $extra = []): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($httpCode);
        $result->setData(['status' => $status] + $extra);
        return $result;
    }
}
