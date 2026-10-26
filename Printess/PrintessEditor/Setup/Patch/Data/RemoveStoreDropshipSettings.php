<?php
declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Production\DropshipMode;

/**
 * Dropshipping is a product-level setting only (decision 2026-10-13): the store-level "Dropshipping" and
 * "Dropship Product" settings are removed. This patch
 *  - deletes their stored values from core_config_data,
 *  - clears the legacy product value 'use_default' ("Use store setting"), which no longer exists, so those
 *    products show "No dropshipping" (not set; a configurable child then follows its parent),
 *  - rewrites the attribute note.
 * Idempotent.
 */
class RemoveStoreDropshipSettings implements DataPatchInterface
{
    public const NOTE = 'How orders of this product are produced. "No dropshipping" (not set) produces a print PDF. "Use a dropship product definition" sends it to the dropshipper and product chosen below. "Never dropship" is an explicit opt-out. For configurable products the simple child product decides first; a child without a setting follows its parent product.';

    private const CONFIG_PATHS = [
        'printess_designer/production/dropship_mode',
        'printess_designer/production/dropship_product_definition',
    ];

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $connection->delete(
            $this->setup->getTable('core_config_data'),
            ['path IN (?)' => self::CONFIG_PATHS]
        );

        /** @var EavSetup $eavSetup */
        $eavSetup    = $this->eavSetupFactory->create(['setup' => $this->setup]);
        $attributeId = $eavSetup->getAttributeId(Product::ENTITY, 'printess_dropship_mode');
        if ($attributeId) {
            $connection->delete(
                $this->setup->getTable('catalog_product_entity_varchar'),
                ['attribute_id = ?' => (int) $attributeId, 'value = ?' => DropshipMode::USE_DEFAULT]
            );
            $eavSetup->updateAttribute(Product::ENTITY, 'printess_dropship_mode', 'default_value', '');
            $eavSetup->updateAttribute(Product::ENTITY, 'printess_dropship_mode', 'note', self::NOTE);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [UpdateDropshipModeDefault::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
