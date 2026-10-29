<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PrintessApi;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Data\OptionSourceInterface;

class PrintSettings extends AbstractSource implements OptionSourceInterface
{
    private const CACHE_KEY_PREFIX = 'printess_print_settings_list_';
    private const CACHE_TAG        = 'PRINTESS_API';
    private const CACHE_LIFETIME   = 600;

    private Config $config;
    private CacheInterface $cache;

    public function __construct(Config $config, CacheInterface $cache)
    {
        $this->config = $config;
        $this->cache  = $cache;
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
        $options = [['value' => '', 'label' => __('-- Default --')]];

        $serviceToken = $this->config->getServiceToken();
        if (empty($serviceToken)) {
            return $options;
        }

        try {
            foreach ($this->loadNames($serviceToken) as $name) {
                $options[] = ['value' => $name, 'label' => $name];
            }
        } catch (\Exception $e) {
            $options[] = ['value' => '', 'label' => 'Error loading settings: ' . $e->getMessage()];
        }

        return $options;
    }

    /**
     * Print-settings names, cached for 10 minutes (keyed by api url + token) so every admin
     * product form / config page render doesn't call the API. Errors propagate and are not cached.
     *
     * @return string[]
     */
    private function loadNames(string $serviceToken): array
    {
        $apiUrl   = $this->config->getApiUrl();
        $cacheKey = self::CACHE_KEY_PREFIX . hash('sha256', $apiUrl . '|' . $serviceToken);

        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $api      = new PrintessApi($serviceToken, $apiUrl);
        $response = $api->readUserSettings(['print-settings-list']);
        $raw  = $response->{'print-settings-list'} ?? '[]';
        $list = is_string($raw) ? (json_decode($raw, true) ?? []) : (array)$raw;

        $names = [];
        foreach ($list as $name) {
            $names[] = (string)$name;
        }

        $this->cache->save((string)json_encode($names), $cacheKey, [self::CACHE_TAG], self::CACHE_LIFETIME);

        return $names;
    }
}
