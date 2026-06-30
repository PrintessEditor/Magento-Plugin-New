<?php
namespace Printess\PrintessEditor\Model\Config\Source;

use Printess\PrintessEditor\Helper\Config;
use Printess\PrintessEditor\Model\PrintessApi;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\Data\OptionSourceInterface;

class PrintSettings extends AbstractSource implements OptionSourceInterface
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
        $options = [['value' => '', 'label' => __('-- Default --')]];

        $serviceToken = $this->config->getServiceToken();
        if (empty($serviceToken)) {
            return $options;
        }

        try {
            $api      = new PrintessApi($serviceToken);
            $response = $api->readUserSettings(['print-settings-list']);
            $raw  = $response->{'print-settings-list'} ?? '[]';
            $list = is_string($raw) ? (json_decode($raw, true) ?? []) : (array)$raw;

            foreach ($list as $name) {
                $name      = (string)$name;
                $options[] = ['value' => $name, 'label' => $name];
            }
        } catch (\Exception $e) {
            $options[] = ['value' => '', 'label' => 'Error loading settings: ' . $e->getMessage()];
        }

        return $options;
    }
}
