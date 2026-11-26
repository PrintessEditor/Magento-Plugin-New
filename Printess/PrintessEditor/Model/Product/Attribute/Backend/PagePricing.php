<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Product\Attribute\Backend;

use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;

class PagePricing extends AbstractBackend
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly State $appState
    ) {
    }

    public function beforeSave($object): void
    {
        $code  = $this->getAttribute()->getAttributeCode();

        // An omitted field is a partial save, not an instruction to delete its rules.
        $value = $object->getData($code);
        try {
            if ($this->appState->getAreaCode() === 'adminhtml') {
                $useDefault = $this->request->getPostValue('use_default');
                if (is_array($useDefault) && !empty($useDefault[$code])) {
                    // Preserve store-view inheritance when "Use Default Value" is enabled.
                    $object->setData($code, null);
                    return;
                }

                $postProduct = $this->request->getPostValue('product');
                if ($this->isTemplateCleared($postProduct, $useDefault)) {
                    // No Printess template any more → rules tied to it are dropped.
                    // (An emptied dynamic-rows list is not posted at all, so this cannot
                    // rely on the field itself arriving empty.)
                    $object->setData($code, '[]');
                    return;
                }
                if (is_array($postProduct) && array_key_exists($code, $postProduct)) {
                    $value = $postProduct[$code];
                }
            }
        } catch (\Exception $e) {
            // area not set (e.g. import/API) — fall through and use getData() value
        }

        if ($value === null) {
            $object->setData($code, null);
            return;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (is_array($value)) {
            $rules = array_values(array_filter($value, static function ($row): bool {
                return is_array($row) && empty($row['delete'])
                    && isset($row['pricePerPage'])
                    && (string)$row['pricePerPage'] !== '';
            }));
            foreach ($rules as &$rule) {
                $rule['conditions']   = trim((string)($rule['conditions'] ?? ''));
                $rule['pricePerPage'] = (float)$rule['pricePerPage'];
            }
            unset($rule);
            $object->setData($code, json_encode($rules));
        } else {
            $object->setData($code, '[]');
        }
    }

    /**
     * True when the admin form is saved with an empty Printess template that is not
     * inherited from the default scope ("Use Default Value" unchecked).
     */
    private function isTemplateCleared(mixed $postProduct, mixed $useDefault): bool
    {
        if (!is_array($postProduct) || !array_key_exists('printess_template', $postProduct)) {
            return false;
        }
        if (is_array($useDefault) && !empty($useDefault['printess_template'])) {
            return false;
        }
        return trim((string)$postProduct['printess_template']) === '';
    }

    public function afterLoad($object): void
    {
        $code  = $this->getAttribute()->getAttributeCode();
        $value = $object->getData($code);
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $object->setData($code, is_array($decoded) ? $decoded : []);
        } elseif (!is_array($value)) {
            $object->setData($code, []);
        }
    }
}
