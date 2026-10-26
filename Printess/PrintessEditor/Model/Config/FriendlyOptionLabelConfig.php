<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the admin-configured Printess friendly option label/value rules
 * (System Config > Printess > Printess Editor > Display > Friendly Option Labels).
 */
class FriendlyOptionLabelConfig
{
    private const CONFIG_PATH_RULES = 'printess_designer/display/friendly_option_labels';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Json
     */
    private $jsonSerializer;

    public function __construct(ScopeConfigInterface $scopeConfig, Json $jsonSerializer)
    {
        $this->scopeConfig = $scopeConfig;
        $this->jsonSerializer = $jsonSerializer;
    }

    /**
     * @param int|string|null $storeId
     * @return array Raw rule rows, each with option_label/option_value/friendly_text keys.
     */
    public function getRules($storeId = null): array
    {
        $value = $this->scopeConfig->getValue(
            self::CONFIG_PATH_RULES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!$value) {
            return [];
        }

        try {
            return (array) $this->jsonSerializer->unserialize($value);
        } catch (\InvalidArgumentException $e) {
            return [];
        }
    }
}
