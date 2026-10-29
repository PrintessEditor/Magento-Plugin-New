<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Production;

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
use Printess\PrintessEditor\Model\Production\RenderResultHandler;
use Psr\Log\LoggerInterface;

/**
 * POST printess/production/callback — Printess "rendering finished" callback (print and dropship jobs in
 * callback mode). Authenticated by the per-job nonce sent as X-Printess-Nonce header in the produce request.
 *
 * Printess retries every non-2xx answer, so: unknown nonce → 404 (nothing we could ever do with it),
 * payload mismatch → 403, unexpected error → 500 (a retry may succeed), otherwise 200.
 */
class Callback implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const NONCE_HEADER  = 'X-Printess-Nonce';
    private const MAX_NONCE_LEN = 128;

    /** A repeated callback must not re-download a PDF or move a dropship job backwards. */
    private const SETTLED_STATUSES = [PdfJob::STATUS_COMPLETE, PdfJob::STATUS_RENDERED, PdfJob::STATUS_SHIPPED];

    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly PdfJobRepository $pdfJobRepository,
        private readonly CallbackVerifier $verifier,
        private readonly RenderResultHandler $renderResultHandler,
        private readonly LoggerInterface $logger
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
            $nonce = trim((string) $this->request->getHeader(self::NONCE_HEADER));
            $job   = $this->findJob($nonce);
            if ($job === null) {
                return $this->respond(404, 'not_found');
            }

            $payload = json_decode((string) $this->request->getContent(), true);
            if (!is_array($payload)) {
                $this->logger->warning(sprintf(
                    'Printess render callback for job %d: body is not a JSON object.',
                    (int) $job->getId()
                ));
                return $this->respond(400, 'bad_request');
            }

            if (!$this->verifier->verifyRender($job->getData(), $payload)) {
                // Never log the nonce or payload contents (result URLs are effectively bearer links).
                $this->logger->warning(sprintf(
                    'Printess render callback rejected for job %d (order %d): jobId does not match.',
                    (int) $job->getId(),
                    (int) $job->getData('order_id')
                ));
                return $this->respond(403, 'forbidden');
            }

            if (in_array((string) $job->getData('status'), self::SETTLED_STATUSES, true)) {
                $this->logger->info(sprintf(
                    'Printess render callback for job %d ignored: job already %s.',
                    (int) $job->getId(),
                    (string) $job->getData('status')
                ));
                return $this->respond(200, 'ok');
            }

            $this->renderResultHandler->apply($job, $payload);
            return $this->respond(200, 'ok');
        } catch (\Throwable $e) {
            $this->logger->error(
                'Printess render callback failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            return $this->respond(500, 'error');
        }
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
        return $stored !== '' && hash_equals($stored, $nonce) ? $job : null;
    }

    private function respond(int $httpCode, string $status): Json
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode($httpCode);
        $result->setData(['status' => $status]);
        return $result;
    }
}
