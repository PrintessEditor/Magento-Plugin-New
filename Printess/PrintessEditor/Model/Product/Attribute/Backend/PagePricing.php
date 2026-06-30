<?php
namespace Printess\PrintessEditor\Model\Product\Attribute\Backend;

use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;

class PagePricing extends AbstractBackend
{
    public function beforeSave($object): void
    {
        $code  = $this->getAttribute()->getAttributeCode();
        $value = $object->getData($code);
        if (is_array($value)) {
            $rules = array_values(array_filter($value, static function (array $row): bool {
                return isset($row['pricePerPage']) && (string)$row['pricePerPage'] !== '';
            }));
            foreach ($rules as &$rule) {
                $rule['conditions']   = trim((string)($rule['conditions'] ?? ''));
                $rule['pricePerPage'] = (float)$rule['pricePerPage'];
            }
            unset($rule);
            $object->setData($code, json_encode($rules));
        }
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
