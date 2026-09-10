<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Sales;

use Printess\PrintessEditor\Model\Config\FriendlyOptionLabelConfig;
use Printess\PrintessEditor\Model\Config\FriendlyOptionLabelConstants;

/**
 * Renders friendlier labels/values for Printess custom options in cart, checkout and
 * order emails, without touching the underlying admin-configured option data (which
 * the Printess editor itself also reads directly).
 *
 * Rules are admin-configurable (System Config > Printess > Printess Editor > Display),
 * so new options can be relabelled without code changes.
 */
class FriendlyOptionLabelFormatter
{
    /**
     * @var FriendlyOptionLabelConfig
     */
    private $config;

    public function __construct(FriendlyOptionLabelConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array $options Array of option entries (each with at least a "label" key).
     * @param int|string|null $storeId
     * @return array
     */
    public function format(array $options, $storeId = null): array
    {
        [$labelMap, $valueMap] = $this->buildMaps($storeId);

        foreach ($options as &$option) {
            if (!is_array($option) || !isset($option['label'])) {
                continue;
            }

            $originalLabel = (string) $option['label'];
            $valuesForLabel = $valueMap[$originalLabel] ?? null;

            if ($valuesForLabel !== null) {
                if (isset($option['value']) && isset($valuesForLabel[$option['value']])) {
                    $option['value'] = $valuesForLabel[$option['value']];
                }
                if (isset($option['print_value']) && isset($valuesForLabel[$option['print_value']])) {
                    $option['print_value'] = $valuesForLabel[$option['print_value']];
                }
            }

            if (isset($labelMap[$originalLabel])) {
                $option['label'] = $labelMap[$originalLabel];
            }
        }
        unset($option);

        return $options;
    }

    /**
     * Splits the flat admin rule rows into a label map and a per-label value map.
     *
     * @param int|string|null $storeId
     * @return array{0: array<string, string>, 1: array<string, array<string, string>>}
     */
    private function buildMaps($storeId): array
    {
        $labelMap = [];
        $valueMap = [];

        foreach ($this->config->getRules($storeId) as $rule) {
            $optionLabel = (string) ($rule[FriendlyOptionLabelConstants::OPTION_LABEL] ?? '');
            $optionValue = (string) ($rule[FriendlyOptionLabelConstants::OPTION_VALUE] ?? '');
            $friendlyText = (string) ($rule[FriendlyOptionLabelConstants::FRIENDLY_TEXT] ?? '');

            if ($optionLabel === '' || $friendlyText === '') {
                continue;
            }

            if ($optionValue === FriendlyOptionLabelConstants::OPTION_VALUE_ANY) {
                $labelMap[$optionLabel] = $friendlyText;
            } else {
                $valueMap[$optionLabel][$optionValue] = $friendlyText;
            }
        }

        return [$labelMap, $valueMap];
    }
}
