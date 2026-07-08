<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PrintessApi;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Data\OptionSourceInterface;

class MagicPhotobookTheme extends AbstractSource implements OptionSourceInterface
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function getAllOptions(): array
    {
        return $this->fetchOptions();
    }

    public function toOptionArray(): array
    {
        return $this->fetchOptions();
    }

    private function fetchOptions(): array
    {
        $options      = [['value' => '', 'label' => __('-- None --')]];
        $serviceToken = $this->config->getServiceToken();

        if (empty($serviceToken)) {
            return $options;
        }

        try {
            $api      = new PrintessApi($serviceToken);
            $response = $api->readUserSettings(['magicPhotobookThemes']);
            $raw      = $response->{'magicPhotobookThemes'} ?? '[]';
            $list     = is_string($raw) ? (json_decode($raw, true) ?? []) : (array)$raw;

            foreach ($list as $theme) {
                // API returns objects: {"n":"ThemeName",...}
                $name = is_array($theme) ? (string)($theme['n'] ?? '') : (string)$theme;
                if ($name === '') {
                    continue;
                }
                $options[] = ['value' => $name, 'label' => $name];
            }
        } catch (\Exception $e) {
            $options[] = ['value' => '', 'label' => 'Error: ' . $e->getMessage()];
        }

        return $options;
    }
}
