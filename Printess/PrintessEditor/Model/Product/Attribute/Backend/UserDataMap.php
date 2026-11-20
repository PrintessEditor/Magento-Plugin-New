<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Product\Attribute\Backend;

use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;
use Printess\PrintessEditor\Model\CustomerFields\UserDataSource;

/**
 * UDP-01: stores the "Prefill user data" mapping as JSON [{fieldName, source}]; rows without a known source are
 * dropped. Same request handling as FormFields (the UI component posts an array, "Use Default Value" clears it).
 */
class UserDataMap extends AbstractBackend
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly State $appState
    ) {
    }

    public function beforeSave($object): void
    {
        $code  = $this->getAttribute()->getAttributeCode();
        $value = $object->getData($code);

        try {
            if ($this->appState->getAreaCode() === 'adminhtml') {
                $useDefault = $this->request->getPostValue('use_default');
                if (is_array($useDefault) && !empty($useDefault[$code])) {
                    $object->setData($code, null);
                    return;
                }
                $postProduct = $this->request->getPostValue('product');
                if (is_array($postProduct) && array_key_exists($code, $postProduct)) {
                    $value = $postProduct[$code];
                }
            }
        } catch (\Exception) {
            // area not set (import/API): use getData()
        }

        if ($value === null) {
            $object->setData($code, null);
            return;
        }
        $object->setData($code, (string) json_encode(UserDataSource::normalizeMap($value)));
    }

    public function afterLoad($object): void
    {
        $code = $this->getAttribute()->getAttributeCode();
        $object->setData($code, UserDataSource::normalizeMap($object->getData($code)));
    }
}
