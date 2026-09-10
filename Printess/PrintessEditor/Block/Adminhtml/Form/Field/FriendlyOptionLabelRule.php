<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Printess\PrintessEditor\Model\Config\FriendlyOptionLabelConstants;

/**
 * Admin grid for mapping raw Printess option labels/values to customer-friendly text,
 * shown in cart, checkout and order emails.
 */
class FriendlyOptionLabelRule extends AbstractFieldArray
{
    /**
     * @inheritDoc
     */
    protected function _prepareToRender()
    {
        $this->addColumn(FriendlyOptionLabelConstants::OPTION_LABEL, [
            'label' => __('Option Label'),
            'class' => 'required-entry',
        ]);
        $this->addColumn(FriendlyOptionLabelConstants::OPTION_VALUE, [
            'label' => __('Option Value (leave blank to relabel the option itself)'),
        ]);
        $this->addColumn(FriendlyOptionLabelConstants::FRIENDLY_TEXT, [
            'label' => __('Friendly Text'),
            'class' => 'required-entry',
        ]);

        $this->_addAfter = false;
    }
}
