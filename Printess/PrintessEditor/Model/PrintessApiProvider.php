<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model;

use Printess\PrintessEditor\Helper\Config;

/**
 * Injectable factory for store-scoped PrintessApi clients. PrintessApi itself takes the token as a
 * plain string (kept for back-compat), so production/dropship code goes through this provider to get
 * the token and API URL of the order's store instead of the default scope.
 */
class PrintessApiProvider
{
    /** @var array<string, PrintessApi> */
    private array $clients = [];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @throws PrintessApiException when no service token is configured for the store
     */
    public function forStore(?int $storeId): PrintessApi
    {
        $token = trim($this->config->getServiceToken($storeId));
        if ($token === '') {
            throw PrintessApiException::notConfigured($storeId);
        }

        $apiUrl = $this->config->getApiUrl($storeId);
        // Keyed by token+url rather than store id so a config change within a long-running cron
        // process never reuses a client built with stale credentials.
        $key = hash('sha256', $token . "\0" . $apiUrl);

        return $this->clients[$key] ??= new PrintessApi($token, $apiUrl);
    }

    public function hasServiceToken(?int $storeId): bool
    {
        return trim($this->config->getServiceToken($storeId)) !== '';
    }
}
