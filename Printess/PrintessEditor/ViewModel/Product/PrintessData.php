<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\ViewModel\Product;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class PrintessData implements ArgumentInterface
{
    public function __construct(
        private readonly LocaleResolver  $localeResolver,
        private readonly CustomerSession $customerSession,
        private readonly \Printess\PrintessEditor\Model\Approval\ApprovalConfig $approvalConfig,
        private readonly \Magento\Framework\UrlInterface $urlBuilder,
    ) {}

    /**
     * SUP-07: store-level flag (safe for full-page cache). Whether *this* customer needs approval is decided by the
     * submit endpoint (supervisors may order directly).
     */
    public function isApprovalEnabled(): bool
    {
        return $this->approvalConfig->isEnabled();
    }

    public function getApprovalSubmitUrl(): string
    {
        return $this->urlBuilder->getUrl('printess/approval/submit');
    }

    public function getLocale(): string
    {
        return str_replace('_', '-', $this->localeResolver->getLocale());
    }

    public function getShopUserId(): string
    {
        return $this->customerSession->isLoggedIn()
            ? (string) $this->customerSession->getCustomerId()
            : '';
    }
}
