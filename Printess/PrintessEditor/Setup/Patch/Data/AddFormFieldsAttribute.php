<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\FormFields;

class AddFormFieldsAttribute implements DataPatchInterface
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

        if ($eavSetup->getAttributeId(Product::ENTITY, 'printess_form_fields')) {
            return $this;
        }

        $eavSetup->addAttribute(Product::ENTITY, 'printess_form_fields', [
            'type'                    => 'text',
            'backend'                 => FormFields::class,
            'label'                   => 'Predefined Form Fields',
            'input'                   => 'textarea',
            'required'                => false,
            'default'                 => null,
            'sort_order'              => 145,
            'global'                  => ScopedAttributeInterface::SCOPE_STORE,
            'visible'                 => true,
            'used_in_product_listing' => false,
            'user_defined'            => true,
            'group'                   => 'Printess',
            'note'                    => 'Static form field values passed to the Printess editor on load.',
        ]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddPagePricingAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
