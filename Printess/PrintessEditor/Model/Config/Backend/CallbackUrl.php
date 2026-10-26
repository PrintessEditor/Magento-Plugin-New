<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Printess\PrintessEditor\Model\Production\CallbackUrlPolicy;

/**
 * Validates the "Callback URL (override, for debugging)" before it is saved.
 *
 * Printess rejects callback URLs that are not https or that point at an IP address, localhost or a
 * non-public host (406 c102 at submit time), so we catch that here instead of letting every job fail later.
 * The value is normalised to end with exactly one '/' because the module appends its routes to it.
 */
class CallbackUrl extends Value
{
    private CallbackUrlPolicy $policy;

    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        CallbackUrlPolicy $policy,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->policy = $policy;
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = trim((string)$this->getValue());

        if ($value === '') {
            $this->setValue('');
            return parent::beforeSave();
        }

        $parts = parse_url($value);
        $host = is_array($parts) ? strtolower(trim((string)($parts['host'] ?? ''), '[]')) : '';

        switch ($this->policy->getProblem($value)) {
            case null:
                break;
            case CallbackUrlPolicy::PROBLEM_IP_ADDRESS:
                throw new LocalizedException(__(
                    'The Callback URL must use a domain name, not an IP address, because Printess cannot call IP addresses.'
                ));
            case CallbackUrlPolicy::PROBLEM_NOT_PUBLIC_HOST:
                throw new LocalizedException(__(
                    'The Callback URL must be a public domain that Printess can reach, not "%1".',
                    $host
                ));
            case CallbackUrlPolicy::PROBLEM_QUERY_FRAGMENT:
                throw new LocalizedException(__(
                    'The Callback URL must be a plain base address without "?" or "#" parts.'
                ));
            default:
                throw new LocalizedException(__(
                    'The Callback URL must be a full https address, e.g. https://shop.example.com/.'
                ));
        }

        $this->setValue(rtrim($value, '/') . '/');

        return parent::beforeSave();
    }
}
