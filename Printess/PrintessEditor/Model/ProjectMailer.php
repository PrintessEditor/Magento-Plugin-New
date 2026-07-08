<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Escaper;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

class ProjectMailer
{
    private const SENDER_PATH = 'trans_email/ident_general/';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly Escaper $escaper
    ) {
    }

    public function send(Project $project, string $templateId): void
    {
        $storeId = (int) $project->getData('store_id');
        $store = $this->storeManager->getStore($storeId);
        $customer = $this->customerRepository->getById((int) $project->getData('customer_id'));
        $expiresAt = (string) $project->getData('expires_at');
        $expiryDate = '';
        if ($expiresAt !== '') {
            try {
                $expiryDate = $this->timezone->formatDateTime(
                    new \DateTime($expiresAt, new \DateTimeZone('UTC')),
                    \IntlDateFormatter::MEDIUM,
                    \IntlDateFormatter::NONE,
                    $store->getConfig('general/locale/code'),
                    $store->getConfig('general/locale/timezone')
                );
            } catch (\Throwable) {
                $expiryDate = '';
            }
        }

        $transport = $this->transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
            ->setTemplateVars([
                'customer_name' => $this->escaper->escapeHtml((string) $customer->getFirstname()),
                'project_name' => $this->escaper->escapeHtml((string) $project->getData('name')),
                'product_name' => $this->escaper->escapeHtml((string) $project->getData('product_name')),
                'expiry_date' => $this->escaper->escapeHtml($expiryDate),
                'projects_url' => $this->escaper->escapeUrl(
                    rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_LINK), '/')
                    . '/printess/project/index'
                ),
                'store_name' => $this->escaper->escapeHtml($store->getFrontendName())
            ])
            ->setFromByScope([
                'name' => $this->scopeConfig->getValue(
                    self::SENDER_PATH . 'name',
                    ScopeInterface::SCOPE_STORE,
                    $storeId
                ),
                'email' => $this->scopeConfig->getValue(
                    self::SENDER_PATH . 'email',
                    ScopeInterface::SCOPE_STORE,
                    $storeId
                )
            ], $storeId)
            ->addTo((string) $customer->getEmail())
            ->getTransport();

        $transport->sendMessage();
    }
}
