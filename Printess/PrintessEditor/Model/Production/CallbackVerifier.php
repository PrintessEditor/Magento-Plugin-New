<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE: decides whether an incoming Printess callback really belongs to the job its nonce resolved to.
 *
 * The nonce lookup (and its constant-time comparison) has already happened in the controller; this class only
 * checks that the payload talks about the same Printess job / Magento order item, so a leaked nonce cannot be
 * replayed against a different item. Inputs are the PdfJob data array and the decoded callback body.
 */
class CallbackVerifier
{
    /**
     * Rendering callback: the payload's jobId must be exactly the Printess job id we stored at submission.
     */
    public function verifyRender(?array $job, array $payload): bool
    {
        if ($job === null) {
            return false;
        }

        $payloadJobId = $this->str($payload['jobId'] ?? null);
        $storedJobId  = $this->str($job['printess_job_id'] ?? null);

        return $payloadJobId !== '' && $payloadJobId === $storedJobId;
    }

    /**
     * Dropship shipped/tracking callback: orderId/lineItemId are what we sent in callbackPayload (Magento order
     * entity id and order item id). printessOrderId is only compared when both sides have one, because the
     * produce response may not have returned it yet when a fast callback arrives.
     */
    public function verifyShipped(?array $job, array $payload): bool
    {
        if ($job === null) {
            return false;
        }

        if ($this->str($job['production_type'] ?? null) !== 'dropship') {
            return false;
        }

        $jobOrderId  = $this->str($job['order_id'] ?? null);
        $jobItemId   = $this->str($job['order_item_id'] ?? null);
        if ($jobOrderId === '' || $jobItemId === '') {
            return false;
        }

        if ($this->str($payload['orderId'] ?? null) !== $jobOrderId) {
            return false;
        }
        if ($this->str($payload['lineItemId'] ?? null) !== $jobItemId) {
            return false;
        }

        $storedPrintessOrderId  = $this->str($job['printess_order_id'] ?? null);
        $payloadPrintessOrderId = $this->str($payload['printessOrderId'] ?? null);
        if ($storedPrintessOrderId !== '' && $payloadPrintessOrderId !== ''
            && $storedPrintessOrderId !== $payloadPrintessOrderId
        ) {
            return false;
        }

        return true;
    }

    /** Scalars only; arrays/objects never match anything. */
    private function str(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return '';
    }
}
