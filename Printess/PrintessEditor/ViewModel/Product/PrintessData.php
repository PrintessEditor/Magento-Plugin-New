<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\ViewModel\Product;

use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class PrintessData implements ArgumentInterface
{
    public function __construct(
        private readonly LocaleResolver  $localeResolver,
    ) {}

    public function getLocale(): string
    {
        return str_replace('_', '-', $this->localeResolver->getLocale());
    }
}
