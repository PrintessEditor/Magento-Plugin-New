<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Cart;

/**
 * Request-scoped holder (shared DI instance) for a native storefront reorder (PMD-23).
 *
 * Magento\Sales\Model\Reorder\Reorder rebuilds the cart from each order item's info_buyRequest via Quote::addProduct,
 * which never dispatches checkout_cart_product_add_after, so the Printess design (additional_options) would be lost.
 * The Reorder plugin stashes the Printess order items here; the Quote::addProduct plugin claims the source of each
 * add while the context is active.
 *
 * Matching (pure, unit-tested): Reorder adds every top-level order item once, grouped by product id, passing the
 * order item's buy request (qty = qty ordered, custom_price stripped). A source is claimed at most once:
 *   1. same product id and identical buy request (volatile keys ignored),
 *   2. else same product id and the saveToken the incoming buy request itself carries.
 * There is deliberately no "first unclaimed source of the product" fallback: a plain (non-Printess) line of the same
 * product would otherwise steal the design of the real Printess line.
 */
class ReorderContext
{
    /** Buy request keys that differ between the stored order item and what Reorder passes to addProduct. */
    private const VOLATILE_KEYS = [
        'qty', 'original_qty', 'custom_price', 'add_to_cart_invalid_product', 'reset_count', 'id', 'item',
        'uenc', 'form_key', 'return_url', 'in_cart', 'selected_configurable_option', 'related_product',
    ];

    private bool $active = false;
    private int $storeId = 0;
    private int $customerId = 0;
    /** @var list<array{key: string, product_id: int, buy_request: array<string, mixed>, options: array<string, mixed>, name: string, claimed: bool}> */
    private array $sources = [];
    /** @var list<string> */
    private array $errors = [];
    /** @var array<string, string> save token => PrintessApi::TOKEN_STATE_* */
    private array $tokenStatus = [];

    /**
     * @param list<array{key?: string, product_id: int, buy_request: array<string, mixed>, options: array<string, mixed>, name?: string}> $sources
     * @throws \LogicException when a reorder is already in progress
     */
    public function start(int $storeId, int $customerId, array $sources): void
    {
        if ($this->active) {
            throw new \LogicException('A Printess reorder is already in progress.');
        }
        $this->active = true;
        $this->storeId = $storeId;
        $this->customerId = $customerId;
        $this->sources = [];
        foreach (array_values($sources) as $i => $source) {
            $this->sources[] = [
                'key'         => (string) ($source['key'] ?? $i),
                'product_id'  => (int) $source['product_id'],
                'buy_request' => self::normalizeBuyRequest((array) $source['buy_request']),
                'options'     => (array) $source['options'],
                'name'        => (string) ($source['name'] ?? ''),
                'claimed'     => false,
            ];
        }
        $this->errors = [];
        $this->tokenStatus = [];
    }

    public function stop(): void
    {
        $this->active = false;
        $this->storeId = 0;
        $this->customerId = 0;
        $this->sources = [];
        $this->errors = [];
        $this->tokenStatus = [];
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getStoreId(): int
    {
        return $this->storeId;
    }

    /** The order's customer id (0 for guest orders). */
    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    /**
     * Claims the source order item for an addProduct call. Returns null when inactive or nothing matches (e.g. a
     * non-Printess item, or a third-party add during the reorder).
     *
     * @param array<string, mixed> $buyRequest
     * @return array{key: string, product_id: int, buy_request: array<string, mixed>, options: array<string, mixed>, name: string}|null
     */
    public function claim(int $productId, array $buyRequest): ?array
    {
        if (!$this->active) {
            return null;
        }
        $index = self::match($this->sources, $productId, $buyRequest);
        if ($index === null) {
            return null;
        }
        $this->sources[$index]['claimed'] = true;
        $source = $this->sources[$index];
        unset($source['claimed']);
        return $source;
    }

    /**
     * @param list<array{product_id: int, buy_request: array<string, mixed>, claimed?: bool}> $sources buy requests normalized
     * @param array<string, mixed> $buyRequest
     */
    public static function match(array $sources, int $productId, array $buyRequest): ?int
    {
        $normalized = self::normalizeBuyRequest($buyRequest);
        $token = (string) ($normalized['saveToken'] ?? '');
        $byToken = null;

        foreach ($sources as $i => $source) {
            if (!empty($source['claimed']) || (int) $source['product_id'] !== $productId) {
                continue;
            }
            if ($source['buy_request'] == $normalized) {
                return $i;
            }
            if ($byToken === null && $token !== '' && (string) ($source['buy_request']['saveToken'] ?? '') === $token) {
                $byToken = $i;
            }
        }

        return $byToken;
    }

    /**
     * @param array<string, mixed> $buyRequest
     * @return array<string, mixed>
     */
    public static function normalizeBuyRequest(array $buyRequest): array
    {
        foreach (self::VOLATILE_KEYS as $key) {
            unset($buyRequest[$key]);
        }
        return self::sortRecursive($buyRequest);
    }

    /**
     * The Printess entries (printess_* keys) of an order item's additional_options, or [] when it has no save token.
     *
     * @param mixed $additionalOptions
     * @return array<string, mixed>
     */
    public static function extractPrintessOptions(mixed $additionalOptions): array
    {
        if (!is_array($additionalOptions)) {
            return [];
        }
        $token = $additionalOptions['printess_save_token']['value'] ?? '';
        if (!is_string($token) || trim($token) === '') {
            return [];
        }
        return array_filter(
            $additionalOptions,
            static fn($key): bool => str_starts_with((string) $key, 'printess_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /** Cached PrintessApi::TOKEN_STATE_* of a save token for this reorder, or null when not checked yet. */
    public function getTokenStatus(string $token): ?string
    {
        return $this->tokenStatus[$token] ?? null;
    }

    public function setTokenStatus(string $token, string $state): void
    {
        $this->tokenStatus[$token] = $state;
    }

    public function addError(string $message): void
    {
        if (!in_array($message, $this->errors, true)) {
            $this->errors[] = $message;
        }
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function sortRecursive(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortRecursive($value);
            } elseif (is_int($value) || is_float($value)) {
                // Stored JSON vs. a live DataObject can differ in scalar type ("5" vs 5).
                $data[$key] = (string) $value;
            }
        }
        ksort($data);
        return $data;
    }
}
