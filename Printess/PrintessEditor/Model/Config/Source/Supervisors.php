<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\Approval\SupervisorProvider;

/**
 * Customers with the Printess Supervisor role, for the "Default Order Supervisor" store setting and product
 * attribute. The website is shown when there is more than one, since supervisors only act in their own website.
 */
class Supervisors extends AbstractSource
{
    public function __construct(
        private readonly SupervisorProvider $supervisorProvider,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function getAllOptions($withEmpty = true, $defaultValues = false): array
    {
        $options = [['value' => '', 'label' => $this->getEmptyLabel()]];
        $multiWebsite = count($this->storeManager->getWebsites()) > 1;
        foreach ($this->supervisorProvider->getSupervisors() as $supervisor) {
            $label = sprintf('%s <%s>', $supervisor['name'], $supervisor['email']);
            if ($multiWebsite) {
                try {
                    $label .= ' (' . $this->storeManager->getWebsite($supervisor['website_id'])->getName() . ')';
                } catch (\Throwable) {
                    // website removed: show without it
                }
            }
            $options[] = ['value' => (string) $supervisor['id'], 'label' => $label];
        }
        return $options;
    }

    protected function getEmptyLabel(): string
    {
        return (string) __('-- None (notify all supervisors) --');
    }
}
