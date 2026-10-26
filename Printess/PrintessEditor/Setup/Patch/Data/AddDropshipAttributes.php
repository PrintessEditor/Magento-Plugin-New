<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Config\Source\DropshipProductDefinitions;
use Printess\PrintessEditor\Model\Config\Source\ProductDropshipMode;

/**
 * Per-product dropship override. Mode defaults to "use_default" so existing products keep
 * following the store config; an explicit "none" lets a product opt out of store-wide dropshipping.
 */
class AddDropshipAttributes implements DataPatchInterface
{
    private ModuleDataSetupInterface $setup;
    private EavSetupFactory $eavSetupFactory;

    public function __construct(ModuleDataSetupInterface $setup, EavSetupFactory $eavSetupFactory)
    {
        $this->setup           = $setup;
        $this->eavSetupFactory = $eavSetupFactory;
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'printess_dropship_mode')) {
            $eavSetup->addAttribute(Product::ENTITY, 'printess_dropship_mode', [
                'type'                    => 'varchar',
                'label'                   => 'Dropshipping',
                'input'                   => 'select',
                'source'                  => ProductDropshipMode::class,
                'required'                => false,
                'default'                 => '',
                'sort_order'              => 160,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'visible'                 => true,
                'used_in_product_listing' => false,
                'user_defined'            => true,
                'group'                   => 'Printess',
                'note'                    => \Printess\PrintessEditor\Setup\Patch\Data\RemoveStoreDropshipSettings::NOTE,
            ]);
        }

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'printess_dropship_product_definition')) {
            $eavSetup->addAttribute(Product::ENTITY, 'printess_dropship_product_definition', [
                'type'                    => 'int',
                'label'                   => 'Dropship Product Definition',
                'input'                   => 'select',
                'source'                  => DropshipProductDefinitions::class,
                'required'                => false,
                'sort_order'              => 161,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'visible'                 => true,
                'used_in_product_listing' => false,
                'user_defined'            => true,
                'group'                   => 'Printess',
                'note'                    => 'The Printess dropship product definition (dropshipper and product) used '
                    . 'when Dropshipping is set to "Use a dropship product definition". The list is loaded from '
                    . 'your Printess account.',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddPrintSettingsAttribute::class, AddSlimUiFullEditorAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
