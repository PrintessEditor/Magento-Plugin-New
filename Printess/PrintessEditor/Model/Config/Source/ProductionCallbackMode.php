<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Printess\PrintessEditor\Helper\Config;

/**
 * Store-config select for "Production status updates" (PMD-26): Printess calls the shop back (default),
 * or the shop polls Printess by cron.
 */
class ProductionCallbackMode implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::CALLBACK_MODE_CALLBACKS, 'label' => __('Callbacks (recommended)')],
            ['value' => Config::CALLBACK_MODE_POLLING, 'label' => __('Polling (check every few minutes)')],
        ];
    }
}
