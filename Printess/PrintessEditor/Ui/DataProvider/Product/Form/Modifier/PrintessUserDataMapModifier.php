<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier;

use Magento\Backend\Model\UrlInterface;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\AbstractModifier;
use Magento\Framework\Stdlib\ArrayManager;
use Printess\PrintessEditor\Model\CustomerFields\UserDataSource;

/**
 * UDP-02: renders printess_user_data_map as a list of the template's form fields, each with a dropdown of account
 * data ("— none —" by default). Shown only while "Prefill User Data" is switched on.
 */
class PrintessUserDataMapModifier extends AbstractModifier
{
    public function __construct(
        private readonly ArrayManager $arrayManager,
        private readonly UrlInterface $backendUrl
    ) {
    }

    public function modifyData(array $data): array
    {
        foreach ($data as &$productData) {
            if (!is_array($productData) || !isset($productData[self::DATA_SOURCE_DEFAULT])) {
                continue;
            }
            $productData[self::DATA_SOURCE_DEFAULT]['printess_user_data_map'] = UserDataSource::normalizeMap(
                $productData[self::DATA_SOURCE_DEFAULT]['printess_user_data_map'] ?? null
            );
        }
        unset($productData);
        return $data;
    }

    public function modifyMeta(array $meta): array
    {
        $mapPath = $this->arrayManager->findPath('printess_user_data_map', $meta, null, 'children');
        if ($mapPath === null) {
            return $meta;
        }

        $sources = [];
        foreach (UserDataSource::LABELS as $value => $label) {
            $sources[] = ['value' => $value, 'label' => (string) __($label)];
        }

        $config = [
            'componentType'      => 'field',
            'formElement'        => 'input',
            'component'          => 'Printess_PrintessEditor/js/product/form/element/user-data-map-editor',
            'elementTmpl'        => 'Printess_PrintessEditor/product/form/element/user-data-map-editor',
            'endpointFormFields' => $this->backendUrl->getUrl('printess/api/formfields'),
            'sources'            => $sources,
            'additionalClasses'  => 'admin__field-wide',
            'notice'             => __(
                'Choose which account data goes into which form field. New designs of logged-in customers only; '
                . 'an empty value (e.g. no phone number) keeps the template or Predefined Form Fields value.'
            ),
        ];

        $switchPath = $this->arrayManager->findPath('printess_prefill_user_data', $meta, null, 'children');
        if ($switchPath !== null) {
            $switchSort = (int) ($this->arrayManager->get($switchPath . '/arguments/data/config/sortOrder', $meta) ?? 0);
            $config['sortOrder'] = $switchSort + 1;
        }

        return $this->arrayManager->merge($mapPath . '/arguments/data/config', $meta, $config);
    }
}
