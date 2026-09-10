<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Framework\App\Config\ConfigResource\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\ScopeInterface;
use Printess\PrintessEditor\Model\Config\FriendlyOptionLabelConstants;

/**
 * Seeds sensible default friendly-label overrides for the well-known Printess custom
 * options (DOCUMENT_SIZE, COVER_TYPE, PAGE_FINISH), so cart/checkout/email display is
 * readable out-of-the-box. Fully admin-editable afterwards via System Config > Printess
 * Editor > Friendly Option Display; this patch never overwrites an existing value.
 */
class SeedFriendlyOptionLabels implements DataPatchInterface
{
    private const CONFIG_PATH_RULES = 'printess_designer/display/friendly_option_labels';

    private ModuleDataSetupInterface $setup;
    private ConfigInterface $configResource;
    private ScopeConfigInterface $scopeConfig;
    private Json $jsonSerializer;

    public function __construct(
        ModuleDataSetupInterface $setup,
        ConfigInterface $configResource,
        ScopeConfigInterface $scopeConfig,
        Json $jsonSerializer
    ) {
        $this->setup = $setup;
        $this->configResource = $configResource;
        $this->scopeConfig = $scopeConfig;
        $this->jsonSerializer = $jsonSerializer;
    }

    public function apply(): self
    {
        $existing = $this->scopeConfig->getValue(self::CONFIG_PATH_RULES, ScopeInterface::SCOPE_STORE);
        if ($existing) {
            // Already configured (either by an admin, or a previous run of this patch) - don't overwrite.
            return $this;
        }

        $rules = [
            [
                FriendlyOptionLabelConstants::OPTION_LABEL => 'DOCUMENT_SIZE',
                FriendlyOptionLabelConstants::OPTION_VALUE => FriendlyOptionLabelConstants::OPTION_VALUE_ANY,
                FriendlyOptionLabelConstants::FRIENDLY_TEXT => 'Document Size',
            ],
            [
                FriendlyOptionLabelConstants::OPTION_LABEL => 'COVER_TYPE',
                FriendlyOptionLabelConstants::OPTION_VALUE => FriendlyOptionLabelConstants::OPTION_VALUE_ANY,
                FriendlyOptionLabelConstants::FRIENDLY_TEXT => 'Cover Type',
            ],
            [
                FriendlyOptionLabelConstants::OPTION_LABEL => 'COVER_TYPE',
                FriendlyOptionLabelConstants::OPTION_VALUE => 'HC',
                FriendlyOptionLabelConstants::FRIENDLY_TEXT => 'Hard Cover',
            ],
            [
                FriendlyOptionLabelConstants::OPTION_LABEL => 'COVER_TYPE',
                FriendlyOptionLabelConstants::OPTION_VALUE => 'SC',
                FriendlyOptionLabelConstants::FRIENDLY_TEXT => 'Soft Cover',
            ],
            [
                FriendlyOptionLabelConstants::OPTION_LABEL => 'PAGE_FINISH',
                FriendlyOptionLabelConstants::OPTION_VALUE => FriendlyOptionLabelConstants::OPTION_VALUE_ANY,
                FriendlyOptionLabelConstants::FRIENDLY_TEXT => 'Page Finish',
            ],
            [
                FriendlyOptionLabelConstants::OPTION_LABEL => 'PAGE_FINISH',
                FriendlyOptionLabelConstants::OPTION_VALUE => 'GF',
                FriendlyOptionLabelConstants::FRIENDLY_TEXT => 'Gloss Finish',
            ],
            [
                FriendlyOptionLabelConstants::OPTION_LABEL => 'PAGE_FINISH',
                FriendlyOptionLabelConstants::OPTION_VALUE => 'SF',
                FriendlyOptionLabelConstants::FRIENDLY_TEXT => 'Standard Finish',
            ],
        ];

        $this->configResource->saveConfig(self::CONFIG_PATH_RULES, $this->jsonSerializer->serialize($rules));

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
