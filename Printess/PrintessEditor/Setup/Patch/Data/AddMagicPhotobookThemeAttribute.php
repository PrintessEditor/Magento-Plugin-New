<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddMagicPhotobookThemeAttribute implements DataPatchInterface
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

        if ($eavSetup->getAttributeId(Product::ENTITY, 'printess_magic_photobook_theme')) {
            return $this;
        }

        $eavSetup->addAttribute(Product::ENTITY, 'printess_magic_photobook_theme', [
            'type'                    => 'varchar',
            'label'                   => 'Magic Photobook Theme',
            'input'                   => 'select',
            'source'                  => \Printess\PrintessEditor\Model\Config\Source\MagicPhotobookTheme::class,
            'required'                => false,
            'default'                 => '',
            'sort_order'              => 160,
            'global'                  => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
            'visible'                 => true,
            'used_in_product_listing' => false,
            'user_defined'            => true,
            'group'                   => 'Printess',
            'note'                    => 'Magic Photobook theme passed to the Printess editor for this product.',
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
