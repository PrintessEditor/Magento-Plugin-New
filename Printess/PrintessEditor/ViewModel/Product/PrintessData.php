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
    ) {
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
