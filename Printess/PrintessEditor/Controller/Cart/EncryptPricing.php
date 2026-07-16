<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Cart;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Encryption\EncryptorInterface;

class EncryptPricing implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface   $request,
        private readonly JsonFactory        $jsonFactory,
        private readonly Session            $customerSession,
        private readonly EncryptorInterface $encryptor
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Customer session provides the authentication boundary; no additional
        // CSRF token needed since the endpoint only encrypts caller-supplied data.
        return true;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false]);
        }

        $body = json_decode((string)$this->request->getContent(), true) ?? [];

        $pageCount     = max(0, (int)($body['pageCount']     ?? 0));
        $includedPages = max(0, (int)($body['includedPages'] ?? 0));
        $formFields    = $body['formFields'] ?? [];
        if (!is_array($formFields)) {
            $formFields = [];
        }

        $payload = json_encode([
            'pageCount'     => $pageCount,
            'includedPages' => $includedPages,
            'formFields'    => $formFields,
        ]);

        $token = $this->encryptor->encrypt($payload);

        return $result->setData(['success' => true, 'token' => $token]);
    }
}
