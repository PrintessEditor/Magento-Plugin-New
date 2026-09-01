<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddSlimUiFullEditorAttribute implements DataPatchInterface
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

        if ($eavSetup->getAttributeId(Product::ENTITY, 'printess_slim_ui_full_editor')) {
            return $this;
        }

        $eavSetup->addAttribute(Product::ENTITY, 'printess_slim_ui_full_editor', [
            'type'                    => 'int',
            'label'                   => 'Allow Switch to Full Editor',
            'input'                   => 'boolean',
            'required'                => false,
            'default'                 => 0,
            'sort_order'              => 111,
            'global'                  => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_STORE,
            'visible'                 => true,
            'used_in_product_listing' => false,
            'user_defined'            => true,
            'group'                   => 'Printess',
            'note'                    => 'Adds a "Switch to Full Editor" button on the Slim UI so the customer can continue '
                . 'their personalisation in the fullscreen Panel editor. Has no effect unless "Use Printess Slim UI" is also enabled.',
        ]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddSlimUiAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
