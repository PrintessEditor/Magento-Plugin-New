<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\PagePricing;

class AddPagePricingAttribute implements DataPatchInterface
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

        if ($eavSetup->getAttributeId(Product::ENTITY, 'printess_page_pricing')) {
            return $this;
        }

        $eavSetup->addAttribute(Product::ENTITY, 'printess_page_pricing', [
            'type'                    => 'text',
            'backend'                 => PagePricing::class,
            'label'                   => 'Page Pricing Rules',
            'input'                   => 'textarea',
            'required'                => false,
            'default'                 => null,
            'sort_order'              => 142,
            'global'                  => ScopedAttributeInterface::SCOPE_STORE,
            'visible'                 => true,
            'used_in_product_listing' => false,
            'user_defined'            => true,
            'group'                   => 'Printess',
            'note'                    => 'Dynamic per-page pricing rules based on Printess form field values.',
        ]);

        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddPricePerPageAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
