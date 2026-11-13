<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Printess\PrintessEditor\Model\Config\Source\ProductSupervisors;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\OrderSupervisor;

/**
 * SUP-06: product-level "Default Order Supervisor", overriding the store setting. Empty = use store default.
 */
class AddOrderSupervisorAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public const ATTRIBUTE_CODE = 'printess_order_supervisor';

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);
        if (!$eavSetup->getAttributeId(Product::ENTITY, self::ATTRIBUTE_CODE)) {
            $eavSetup->addAttribute(Product::ENTITY, self::ATTRIBUTE_CODE, [
                'type'                    => 'int',
                'label'                   => 'Default Order Supervisor',
                'input'                   => 'select',
                'source'                  => ProductSupervisors::class,
                'backend'                 => OrderSupervisor::class,
                'required'                => false,
                'sort_order'              => 170,
                'global'                  => ScopedAttributeInterface::SCOPE_STORE,
                'visible'                 => true,
                'used_in_product_listing' => false,
                'user_defined'            => true,
                'group'                   => 'Printess',
                'note'                    => 'Supervisor notified when a customer submits a design of this product '
                    . 'for approval. Overrides the store setting (Printess Designer → Supervisor Approval). Only '
                    . 'used while Supervisor approval is enabled.',
            ]);
        }
        return $this;
    }

    public function revert(): void
    {
        $this->eavSetupFactory->create(['setup' => $this->setup])
            ->removeAttribute(Product::ENTITY, self::ATTRIBUTE_CODE);
    }

    public static function getDependencies(): array
    {
        return [AddDropshipAttributes::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
