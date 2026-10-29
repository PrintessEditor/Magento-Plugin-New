<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory;
use Printess\PrintessEditor\Model\Production\ReproductionContext;
use Printess\PrintessEditor\Model\Production\ReproductionLink;
use Psr\Log\LoggerInterface;

/**
 * Hidden carrier for order copies (PMD-21): one rate at 0, titled like the original order's shipping description, so
 * the copy shows the same shipping. Offered only while ReproductionContext is active — never in the storefront or in
 * a regular admin order. There is deliberately no system.xml group; config lives in etc/config.xml.
 */
class Reproduction extends AbstractCarrier implements CarrierInterface
{
    public const CODE = 'printess_reproduction';
    /** carrier_method as stored in quote/order `shipping_method`. */
    public const METHOD_CODE = self::CODE . '_' . self::CODE;

    /** @var string */
    protected $_code = self::CODE;

    /** @var bool */
    protected $_isFixed = true;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly ReproductionContext $context,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active') || !$this->context->isActive()) {
            return false;
        }

        $fallback = (string) ($this->getConfigData('title') ?: __('Reproduction (free)'));
        $titles = ReproductionLink::splitShippingDescription($this->context->getShippingDescription(), $fallback);

        $method = $this->rateMethodFactory->create();
        $method->setCarrier(self::CODE);
        $method->setCarrierTitle($titles['carrier_title']);
        $method->setMethod(self::CODE);
        $method->setMethodTitle($titles['method_title']);
        $method->setPrice(0.0);
        $method->setCost(0.0);

        $result = $this->rateResultFactory->create();
        $result->append($method);
        return $result;
    }

    public function getAllowedMethods()
    {
        return [self::CODE => (string) ($this->getConfigData('title') ?: __('Reproduction (free)'))];
    }
}
