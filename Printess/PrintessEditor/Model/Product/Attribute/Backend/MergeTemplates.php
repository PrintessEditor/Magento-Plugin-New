<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Product\Attribute\Backend;

use Magento\Eav\Model\Entity\Attribute\Backend\AbstractBackend;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\State;

class MergeTemplates extends AbstractBackend
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly State $appState
    ) {}

    public function beforeSave($object): void
    {
        $code  = $this->getAttribute()->getAttributeCode();
        $value = $object->getData($code);

        try {
            if ($this->appState->getAreaCode() === 'adminhtml') {
                $useDefault = $this->request->getPostValue('use_default');
                if (is_array($useDefault) && array_key_exists($code, $useDefault)) {
                    $object->setData($code, null);
                    return;
                }

                $postProduct = $this->request->getPostValue('product');
                if (is_array($postProduct)) {
                    $value = array_key_exists($code, $postProduct) ? $postProduct[$code] : [];
                }
            }
        } catch (\Exception $e) {
            // area not set (e.g. import/API) — fall through and use getData() value
        }

        if (is_array($value)) {
            $rows = array_values(array_filter($value, static function ($row): bool {
                return is_array($row)
                    && empty($row['delete'])
                    && trim((string)($row['mergeTemplate'] ?? '')) !== '';
            }));
            foreach ($rows as &$row) {
                $row['mergeTemplate'] = trim((string)$row['mergeTemplate']);
                $mergeMode = trim((string)($row['mergeMode'] ?? ''));
                if ($mergeMode !== '') {
                    $row['mergeMode'] = $mergeMode;
                } else {
                    unset($row['mergeMode']);
                }
            }
            unset($row);
            $object->setData($code, $rows !== [] ? json_encode(array_values($rows)) : null);
        } else {
            $object->setData($code, null);
        }
    }

    public function afterLoad($object): void
    {
        $code  = $this->getAttribute()->getAttributeCode();
        $value = $object->getData($code);

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $object->setData($code, $decoded);
            } else {
                // Legacy single-value field — migrate to array format transparently
                $object->setData($code, [['mergeTemplate' => $value]]);
            }
        } elseif (!is_array($value)) {
            $object->setData($code, []);
        }
    }
}
