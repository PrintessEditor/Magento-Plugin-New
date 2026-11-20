<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\UserDataMap;

/**
 * UDP-01: "Prefill user data" switch and the mapping "template form field → account data" per product.
 */
class AddUserDataPrefillAttributes implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'printess_prefill_user_data')) {
            $eavSetup->addAttribute(Product::ENTITY, 'printess_prefill_user_data', [
                'type'                    => 'int',
                'label'                   => 'Prefill User Data',
                'input'                   => 'boolean',
                'source'                  => Boolean::class,
                'required'                => false,
                'default'                 => 0,
                'sort_order'              => 146,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'visible'                 => true,
                'used_in_product_listing' => false,
                'user_defined'            => true,
                'group'                   => 'Printess',
                'note'                    => 'Fills the logged-in customer\'s name, email, phone or billing address '
                    . 'into the template form fields chosen below (new designs only).',
            ]);
        }

        if (!$eavSetup->getAttributeId(Product::ENTITY, 'printess_user_data_map')) {
            $eavSetup->addAttribute(Product::ENTITY, 'printess_user_data_map', [
                'type'                    => 'text',
                'backend'                 => UserDataMap::class,
                'label'                   => 'User Data Form Fields',
                'input'                   => 'textarea',
                'required'                => false,
                'default'                 => null,
                'sort_order'              => 147,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'visible'                 => true,
                'used_in_product_listing' => false,
                'user_defined'            => true,
                'group'                   => 'Printess',
            ]);
        }

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddFormFieldsAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
