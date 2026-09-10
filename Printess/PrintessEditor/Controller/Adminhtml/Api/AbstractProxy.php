<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Printess\PrintessEditor\Helper\Config;

abstract class AbstractProxy extends Action
{
    private const API_BASE = 'https://api.printess.com';
    public const ADMIN_RESOURCE = 'Printess_PrintessEditor::config';

    public function __construct(
        Context $context,
        protected readonly RawFactory $rawResultFactory,
        protected readonly Config $config
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed(): bool
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }

    protected function proxy(string $endpoint, string $body): Raw
    {
        $result = $this->rawResultFactory->create();
        $result->setHeader('Content-Type', 'application/json; charset=utf-8');

        $serviceToken = trim($this->config->getServiceToken());
        if ($serviceToken === '') {
            $result->setHttpResponseCode(503);
            $result->setContents(json_encode(['error' => 'Printess service token not configured.']));
            return $result;
        }

        $ch = curl_init(self::API_BASE . $endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $serviceToken,
            ],
        ]);
        $response   = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $result->setHttpResponseCode(502);
            $result->setContents(json_encode(['error' => 'Printess API unreachable: ' . $curlError]));
            return $result;
        }

        // Ensure we always return JSON so the browser never receives an HTML page
        // (e.g. a Printess CDN 401/403 page) that would be misread as a Magento auth error.
        json_decode($response);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result->setHttpResponseCode(502);
            $result->setContents(json_encode([
                'error' => sprintf(
                    'Printess API returned a non-JSON response (HTTP %d). '
                    . 'Check that the service token is correct in Printess Designer → API Token.',
                    $statusCode
                ),
            ]));
            return $result;
        }

        $result->setHttpResponseCode($statusCode ?: 500);
        $result->setContents($response);
        return $result;
    }
}
