<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * D14: a product without a dropship setting is not dropshipped; the store default applies only to
 * products explicitly set to "Use store setting". Changes the attribute default from 'use_default'
 * to empty and rewrites the help text. Stored product values are deliberately left untouched.
 */
class UpdateDropshipModeDefault implements DataPatchInterface
{
    public const NOTE = 'How orders of this product are produced. "No dropshipping" (not set) never '
        . 'dropships; the store setting is only applied with "Use store setting". "Never dropship" is an '
        . 'explicit opt-out. For configurable products the simple child product decides first; a child '
        . 'without a setting follows its parent product.';

    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    public function apply(): self
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->setup]);

        if ($eavSetup->getAttributeId(Product::ENTITY, 'printess_dropship_mode')) {
            $eavSetup->updateAttribute(Product::ENTITY, 'printess_dropship_mode', 'default_value', '');
            $eavSetup->updateAttribute(Product::ENTITY, 'printess_dropship_mode', 'note', self::NOTE);
        }

        return $this;
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
