<?php
namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddPrintSettingsAttribute implements DataPatchInterface
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

        $eavSetup->addAttribute(Product::ENTITY, 'printess_print_settings', [
            'type'                    => 'varchar',
            'label'                   => 'Print Settings',
            'input'                   => 'select',
            'source'                  => \Printess\PrintessEditor\Model\Config\Source\PrintSettings::class,
            'required'                => false,
            'default'                 => '',
            'sort_order'              => 155,
            'global'                  => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
            'visible'                 => true,
            'used_in_product_listing' => false,
            'user_defined'            => true,
            'group'                   => 'Printess',
            'note'                    => 'Overrides the shop-level print settings for this product. Leave at "Default" to use the shop setting.',
        ]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddThemeAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
