<?php
namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class UpdateProductAttributeListing implements DataPatchInterface
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
        $eavSetup->updateAttribute(Product::ENTITY, 'printess_template', 'used_in_product_listing', true);
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
