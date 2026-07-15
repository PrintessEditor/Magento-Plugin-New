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
    ) {}

    public function beforeSave($object): void
    {
        $code  = $this->getAttribute()->getAttributeCode();

        // In adminhtml form saves, PHP omits keys entirely when the submitted array
        // is empty (all dynamic rows deleted). Read POST directly so we can tell the
        // difference between "field absent = all deleted" and the DB-loaded value.
        $value = $object->getData($code);
        try {
            if ($this->appState->getAreaCode() === 'adminhtml') {
                $useDefault = $this->request->getPostValue('use_default');
                if (is_array($useDefault) && array_key_exists($code, $useDefault)) {
                    // Preserve store-view inheritance when "Use Default Value" is enabled.
                    $object->setData($code, null);
                    return;
                }

                $postProduct = $this->request->getPostValue('product');
                if (is_array($postProduct)) {
                    // Dynamic rows submit no key when emptied; treat missing as explicit clear.
                    $value = array_key_exists($code, $postProduct) ? $postProduct[$code] : [];
                }
            }
        } catch (\Exception $e) {
            // area not set (e.g. import/API) — fall through and use getData() value
        }

        if (is_array($value)) {
            $rules = array_values(array_filter($value, static function (array $row): bool {
                return empty($row['delete'])
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
