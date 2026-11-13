<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Customer\Model\Customer;
use Magento\Customer\Setup\CustomerSetup;
use Magento\Customer\Setup\CustomerSetupFactory;
use Magento\Eav\Model\Entity\Attribute\Source\Boolean;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;

/**
 * SUP-04: customer attribute "Printess Supervisor". Only in the admin customer form (never in
 * customer_account_edit/create), shown and filterable in the customer grid.
 */
class AddSupervisorCustomerAttribute implements DataPatchInterface, PatchRevertableInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly CustomerSetupFactory $customerSetupFactory
    ) {
    }

    public function apply(): self
    {
        $this->setup->getConnection()->startSetup();
        /** @var CustomerSetup $customerSetup */
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->setup]);
        $code = SupervisorProvider::ATTRIBUTE_CODE;

        if (!$customerSetup->getAttributeId(Customer::ENTITY, $code)) {
            $customerSetup->addAttribute(Customer::ENTITY, $code, [
                'type'                  => 'int',
                'label'                 => 'Printess Supervisor',
                'input'                 => 'boolean',
                'source'                => Boolean::class,
                'required'              => false,
                'default'               => 0,
                'visible'               => true,
                'user_defined'          => true,
                'system'                => false,
                'position'              => 1000,
                'sort_order'            => 1000,
                'is_used_in_grid'       => true,
                'is_visible_in_grid'    => true,
                'is_filterable_in_grid' => true,
                'is_searchable_in_grid' => false,
                'note'                  => 'Supervisors approve Printess designs of other customers of this website '
                    . 'before they can be ordered (Printess Designer → Supervisor Approval).',
            ]);
        }

        $attributeSetId = $customerSetup->getDefaultAttributeSetId(Customer::ENTITY);
        $attributeGroupId = $customerSetup->getDefaultAttributeGroupId(Customer::ENTITY, $attributeSetId);
        $attribute = $customerSetup->getEavConfig()->getAttribute(Customer::ENTITY, $code);
        $attribute->addData([
            'attribute_set_id'   => $attributeSetId,
            'attribute_group_id' => $attributeGroupId,
            'used_in_forms'      => ['adminhtml_customer'],
        ]);
        $attribute->save();

        $this->setup->getConnection()->endSetup();
        return $this;
    }

    public function revert(): void
    {
        $customerSetup = $this->customerSetupFactory->create(['setup' => $this->setup]);
        $customerSetup->removeAttribute(Customer::ENTITY, SupervisorProvider::ATTRIBUTE_CODE);
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
