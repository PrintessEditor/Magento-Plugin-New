<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Printess\PrintessEditor\Model\Approval\ApprovalService;
use Psr\Log\LoggerInterface;

/**
 * Base for the storefront approval endpoints (route printess/approval/*). All POST endpoints rely on Magento's
 * form-key CSRF check (no CsrfAwareActionInterface override), customer id always comes from the session.
 */
abstract class AbstractApprovalAction extends Action
{
    public function __construct(
        Context $context,
        protected readonly CustomerSession $customerSession,
        protected readonly JsonFactory $jsonFactory,
        protected readonly ApprovalService $approvalService,
        protected readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    protected function customerId(): int
    {
        return (int) $this->customerSession->getCustomerId();
    }

    protected function json(array $data, int $code = 200): Json
    {
        $result = $this->jsonFactory->create();
        if ($code !== 200) {
            $result->setHttpResponseCode($code);
        }
        return $result->setData($data);
    }

    protected function notLoggedIn(): Json
    {
        return $this->json([
            'success' => false,
            'login'   => true,
            'message' => (string) __('Please sign in to continue.'),
        ], 401);
    }

    /** Runs $fn and turns exceptions into a JSON error (no stack traces). */
    protected function jsonCall(callable $fn): Json
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $this->notLoggedIn();
        }
        try {
            return $this->json(array_merge(['success' => true], $fn()));
        } catch (LocalizedException $e) {
            return $this->json(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('Printess approval: ' . $e->getMessage(), ['exception' => $e]);
            return $this->json([
                'success' => false,
                'message' => (string) __('Something went wrong. Please reload the page and try again.'),
            ]);
        }
    }

    protected function message(): ?string
    {
        $message = $this->getRequest()->getParam('message');
        return $message === null ? null : trim((string) $message);
    }

    /** @return array<string,mixed>|null */
    protected function buyRequest(): ?array
    {
        $raw = (string) $this->getRequest()->getParam('buy_request', '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        // Keep only the fields add-to-cart understands.
        $out = [];
        foreach (['super_attribute', 'options'] as $group) {
            if (isset($decoded[$group]) && is_array($decoded[$group])) {
                foreach ($decoded[$group] as $id => $value) {
                    if (is_scalar($value) && ctype_digit((string) $id)) {
                        $out[$group][(string) $id] = (string) $value;
                    }
                }
            }
        }
        $qty = (float) ($decoded['qty'] ?? 1);
        $out['qty'] = $qty > 0 ? $qty : 1;
        return $out;
    }
}
