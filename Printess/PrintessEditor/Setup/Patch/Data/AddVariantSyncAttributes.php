<?php
namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddVariantSyncAttributes implements DataPatchInterface
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

        $eavSetup->addAttribute(Product::ENTITY, 'printess_variant_field', [
            'type'                    => 'varchar',
            'label'                   => 'Printess Variant Form Field',
            'input'                   => 'text',
            'required'                => false,
            'default'                 => '',
            'sort_order'              => 120,
            'global'                  => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
            'visible'                 => true,
            'used_in_product_listing' => false,
            'user_defined'            => true,
            'group'                   => 'Printess',
            'note'                    => 'The Printess FormField name that corresponds to the variant attribute (e.g. "material").',
        ]);

        $eavSetup->addAttribute(Product::ENTITY, 'printess_variant_attribute', [
            'type'                    => 'varchar',
            'label'                   => 'Printess Variant Magento Attribute',
            'input'                   => 'text',
            'required'                => false,
            'default'                 => '',
            'sort_order'              => 130,
            'global'                  => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
            'visible'                 => true,
            'used_in_product_listing' => false,
            'user_defined'            => true,
            'group'                   => 'Printess',
            'note'                    => 'The Magento configurable attribute code that maps to the FormField above (e.g. "color").',
        ]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddProductAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
