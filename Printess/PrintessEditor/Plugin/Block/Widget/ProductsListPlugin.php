<?php
namespace Printess\PrintessEditor\Plugin\Block\Widget;

use Magento\CatalogWidget\Block\Product\ProductsList;

class ProductsListPlugin
{
    public function afterGetTemplate(ProductsList $subject, string $result): string
    {
        if ($result === 'Magento_CatalogWidget::product/widget/content/grid.phtml') {
            return 'Printess_PrintessEditor::product/widget/content/grid.phtml';
        }
        return $result;
    }
}
