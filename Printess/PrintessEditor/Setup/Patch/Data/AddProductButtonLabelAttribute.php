<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddProductButtonLabelAttribute implements DataPatchInterface
{
    public const ATTRIBUTE_CODE = 'printess_product_btn_label';

    public function __construct(
        private EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): void
    {
        $eavSetup = $this->eavSetupFactory->create();
        if ($eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            return;
        }

        $eavSetup->addAttribute(
            Product::ENTITY,
            self::ATTRIBUTE_CODE,
            [
                'label' => 'Printess Product Button Label',
                'group' => 'Printess',
                'type' => 'varchar',
                'input' => 'text',
                'global' => ScopedAttributeInterface::SCOPE_STORE,
                'visible' => true,
                'required' => false,
                'user_defined' => true,
                'default' => '',
                'searchable' => false,
                'filterable' => false,
                'comparable' => false,
                'visible_on_front' => false,
                'unique' => false,
                'used_in_product_listing' => false,
                'apply_to' => '',
                'sort_order' => 95,
            ]
        );
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
