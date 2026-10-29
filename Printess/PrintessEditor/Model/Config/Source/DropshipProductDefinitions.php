<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Config\Source;

use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Printess\PrintessEditor\Helper\Config;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\PrintessApiProvider;

/**
 * Product definitions available for dropshipping, loaded from Printess /productDefinitions/load.
 *
 * Two shapes: toOptionArray() is grouped by dropshipper (rendered as <optgroup> in system.xml and in
 * the product form via PrintessDropshipModifier); getAllOptions() is flat with "Dropshipper › Definition"
 * labels, because EAV option lookups (getOptionText, grids) cannot handle nested values.
 *
 * The token is resolved for the store in scope: the admin request's 'store' / 'website' param
 * (config page, product form), or an explicit store id (order view). Falls back to default scope.
 *
 * The normalized list is cached for 10 minutes per API url + token so admin pages don't hit the
 * API on every render. Failures are cached for 60 seconds, so a bad token does not make every
 * order view / product form block on the API; a fixed token uses a new cache key and takes
 * effect immediately.
 */
class DropshipProductDefinitions extends AbstractSource implements OptionSourceInterface
{
    private const CACHE_KEY_PREFIX = 'printess_dropship_product_definitions_';
    private const CACHE_TAG        = 'PRINTESS_API';
    private const CACHE_LIFETIME   = 600;
    private const ERROR_LIFETIME   = 60;
    private const ERROR_SUFFIX     = ':error';
    private const OTHER_GROUP      = 'Other';
    private const SEPARATOR        = ' › ';

    /** @var array<int, array{id:int, label:string, dropshipper:?string}>|null definitions of the last load() */
    private ?array $definitions = null;
    private ?string $loadError = null;

    /** @var array<string, array{definitions:?array, error:?string}> per-request memo, keyed by store */
    private array $memo = [];

    public function __construct(
        private readonly PrintessApiProvider $apiProvider,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function toOptionArray(): array
    {
        $options = [$this->emptyOption()];
        if (!$this->load($this->resolveRequestStoreId())) {
            return $this->withError($options);
        }

        $groups = [];
        foreach ($this->definitions as $definition) {
            $group = $definition['dropshipper'] ?? self::OTHER_GROUP;
            $groups[$group][] = ['value' => (string) $definition['id'], 'label' => $this->withId($definition)];
        }

        uksort($groups, [$this, 'compareGroups']);
        foreach ($groups as $label => $values) {
            usort($values, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
            $options[] = ['label' => (string) $label, 'value' => $values];
        }

        return $options;
    }

    public function getAllOptions(): array
    {
        if ($this->_options !== null) {
            return $this->_options;
        }

        $options = [$this->emptyOption()];
        if (!$this->load($this->resolveRequestStoreId())) {
            // Not memoized into $_options; the failure is memoized per request and cached for 60s.
            return $this->withError($options);
        }

        $flat = [];
        foreach ($this->definitions as $definition) {
            $flat[] = ['value' => (string) $definition['id'], 'label' => $this->formatLabel($definition)];
        }
        usort($flat, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $this->_options = array_merge($options, $flat);
    }

    /**
     * "Dropshipper › Definition" for the given id, or null when unknown / not loadable.
     * Pass the store the id belongs to (e.g. a job's store_id); null resolves from the request.
     */
    public function getDefinitionLabel(int $id, ?int $storeId = null): ?string
    {
        if (!$this->load($storeId ?? $this->resolveRequestStoreId())) {
            return null;
        }
        foreach ($this->definitions as $definition) {
            if ($definition['id'] === $id) {
                return $this->formatLabel($definition);
            }
        }

        return null;
    }

    /**
     * Fills $this->definitions from cache or API. Returns false (and sets $loadError) on failure;
     * returns true with an empty list when no service token is configured.
     */
    private function load(?int $storeId): bool
    {
        $memoKey = $storeId === null ? 'default' : (string) $storeId;
        if (!isset($this->memo[$memoKey])) {
            $this->memo[$memoKey] = $this->fetch($storeId);
        }
        $this->definitions = $this->memo[$memoKey]['definitions'];
        $this->loadError   = $this->memo[$memoKey]['error'];

        return $this->definitions !== null;
    }

    /**
     * @return array{definitions:?array, error:?string}
     */
    private function fetch(?int $storeId): array
    {
        if (!$this->apiProvider->hasServiceToken($storeId)) {
            return ['definitions' => [], 'error' => null];
        }

        $cacheKey = self::CACHE_KEY_PREFIX . hash(
            'sha256',
            $this->config->getApiUrl($storeId) . '|' . $this->config->getServiceToken($storeId)
        );

        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return ['definitions' => $decoded, 'error' => null];
            }
        }

        $cachedError = $this->cache->load($cacheKey . self::ERROR_SUFFIX);
        if (is_string($cachedError) && $cachedError !== '') {
            return ['definitions' => null, 'error' => $cachedError];
        }

        try {
            $response = $this->apiProvider->forStore($storeId)->loadProductDefinitions();
        } catch (\Throwable $e) {
            $error = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $this->cache->save($error, $cacheKey . self::ERROR_SUFFIX, [self::CACHE_TAG], self::ERROR_LIFETIME);
            return ['definitions' => null, 'error' => $error];
        }

        $definitions = $this->normalize($response);
        $this->cache->save(
            (string) json_encode($definitions),
            $cacheKey,
            [self::CACHE_TAG],
            self::CACHE_LIFETIME
        );

        return ['definitions' => $definitions, 'error' => null];
    }

    /**
     * Store whose token applies to the current admin page: 'store' param (config page store
     * scope, product form store switcher; 0 = default), else 'website' param (config page
     * website scope, mapped to that website's default store), else null (default scope).
     */
    private function resolveRequestStoreId(): ?int
    {
        try {
            $store = (int) $this->request->getParam('store', 0);
            if ($store > 0) {
                return $store;
            }
            $website = (int) $this->request->getParam('website', 0);
            if ($website > 0) {
                $defaultStore = $this->storeManager->getWebsite($website)->getDefaultStore();
                if ($defaultStore !== null && (int) $defaultStore->getId() > 0) {
                    return (int) $defaultStore->getId();
                }
            }
        } catch (\Throwable) {
            // Unknown website/store id: fall back to default scope.
        }

        return null;
    }

    /**
     * Mirrors the Shopware normalizeDropshipProductDefinitions(): tolerant of missing keys,
     * definitions whose dropshipper is unknown get dropshipper null (shown under "Other").
     *
     * @return array<int, array{id:int, label:string, dropshipper:?string}>
     */
    private function normalize(array $data): array
    {
        $dropshipperNames = [];
        foreach ((is_array($data['dropshippers'] ?? null) ? $data['dropshippers'] : []) as $dropshipper) {
            if (is_array($dropshipper) && isset($dropshipper['id'], $dropshipper['display'])) {
                $dropshipperNames[(int) $dropshipper['id']] = (string) $dropshipper['display'];
            }
        }

        $definitions = [];
        foreach ((is_array($data['productDefinitions'] ?? null) ? $data['productDefinitions'] : []) as $definition) {
            if (!is_array($definition) || !isset($definition['id']) || (int) $definition['id'] <= 0) {
                continue;
            }
            $dropshipperId = isset($definition['dropshipperId']) ? (int) $definition['dropshipperId'] : null;
            $definitions[] = [
                'id'          => (int) $definition['id'],
                'label'       => (string) ($definition['display'] ?? $definition['id']),
                'dropshipper' => $dropshipperId !== null ? ($dropshipperNames[$dropshipperId] ?? null) : null,
            ];
        }

        return $definitions;
    }

    private function formatLabel(array $definition): string
    {
        return ($definition['dropshipper'] ?? self::OTHER_GROUP) . self::SEPARATOR . $this->withId($definition);
    }

    /**
     * Definition name plus its Printess id: Printess accounts often hold several definitions with the same
     * display name (e.g. "Printess Fulfillment Template" twice), which are otherwise indistinguishable.
     */
    private function withId(array $definition): string
    {
        return $definition['label'] . ' (#' . $definition['id'] . ')';
    }

    /** Alphabetical, but "Other" always last. */
    private function compareGroups(string|int $a, string|int $b): int
    {
        $a = (string) $a;
        $b = (string) $b;
        if ($a === self::OTHER_GROUP xor $b === self::OTHER_GROUP) {
            return $a === self::OTHER_GROUP ? 1 : -1;
        }

        return strnatcasecmp($a, $b);
    }

    private function emptyOption(): array
    {
        return ['value' => '', 'label' => __('-- Select a product definition --')];
    }

    private function withError(array $options): array
    {
        $options[] = [
            'value' => '',
            'label' => __('Could not load product definitions: %1', (string) $this->loadError),
        ];

        return $options;
    }
}
