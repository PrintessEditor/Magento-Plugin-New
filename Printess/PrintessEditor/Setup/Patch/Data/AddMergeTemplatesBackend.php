<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\MergeTemplates;

class AddMergeTemplatesBackend implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {}

    public function apply(): self
    {
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);
        $eavSetup->updateAttribute(
            Product::ENTITY,
            'printess_merge_template',
            'backend_model',
            MergeTemplates::class
        );
        return $this;
    }

    public static function getDependencies(): array
    {
        return [AddMergeTemplateAttribute::class];
    }

    public function getAliases(): array
    {
        return [];
    }
}
