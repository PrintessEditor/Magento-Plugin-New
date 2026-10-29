<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\Template\FormFieldsLoader;

/**
 * Returns the raw `/template/formFields/list` response (published version) with the base template's
 * form fields merged into `formFields`, plus `baseTemplate` and `baseTemplateError` (PMD-19).
 */
class FormFields extends AbstractProxy implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        RawFactory $rawResultFactory,
        Config $config,
        private readonly FormFieldsLoader $formFieldsLoader
    ) {
        parent::__construct($context, $rawResultFactory, $config);
    }

    public function execute(): Raw
    {
        $body = (string) $this->getRequest()->getContent();
        $data = json_decode($body, true) ?: [];

        $result = $this->rawResultFactory->create();
        $result->setHeader('Content-Type', 'application/json; charset=utf-8');

        $serviceToken = trim($this->config->getServiceToken());
        if ($serviceToken === '') {
            $result->setHttpResponseCode(503);
            $result->setContents(json_encode(['error' => 'Printess service token not configured.']));
            return $result;
        }

        $response = $this->formFieldsLoader->load($serviceToken, (string) ($data['templateName'] ?? ''));
        $result->setHttpResponseCode($response['status']);
        $result->setContents($response['body']);
        return $result;
    }
}
