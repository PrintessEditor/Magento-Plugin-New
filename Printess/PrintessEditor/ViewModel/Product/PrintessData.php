<?php
namespace Printess\PrintessEditor\ViewModel\Product;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class PrintessData implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly LocaleResolver  $localeResolver,
    ) {}

    public function isLoggedIn(): bool
    {
        return (bool)$this->customerSession->isLoggedIn();
    }

    public function getLocale(): string
    {
        return str_replace('_', '-', $this->localeResolver->getLocale());
    }
}
