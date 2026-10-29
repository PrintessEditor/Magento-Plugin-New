<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Request-scoped holder for one order copy in progress (PMD-21, spike S1).
 *
 * OrderReproductionService starts it before building the copy's quote and stops it (in a `finally`) right after the
 * quote is submitted. While it is active, and only for the quote flagged with the same original order id:
 *  - the `printess_reproduction` total collector offsets the items with a discount titled with the merchant message;
 *  - `Magento\Tax\Model\Config::applyTaxAfterDiscount()` reports true (Plugin\Tax\ForceTaxAfterDiscount);
 *  - the hidden carrier `printess_reproduction` offers one free rate titled like the original shipping;
 *  - the Zero Subtotal Checkout method `free` is available even when disabled in config;
 *  - the minimum order amount check is skipped;
 *  - Observer\PrepareReproductionOrder writes the pending order data onto the order before it is placed.
 *
 * PURE: no Magento dependency. Shared (singleton) through the object manager, so every consumer sees the same state.
 */
class ReproductionContext
{
    private ?int $originalOrderId = null;
    private string $message = '';
    private string $shippingDescription = '';
    private bool $discountEnabled = true;
    /** @var array<string, mixed> */
    private array $pendingOrderData = [];

    /**
     * @param array<string, mixed> $pendingOrderData order fields to set before placement (column => value)
     * @throws \LogicException when a copy is already in progress
     */
    public function start(
        int $originalOrderId,
        string $message,
        string $shippingDescription = '',
        array $pendingOrderData = []
    ): void {
        if ($this->originalOrderId !== null) {
            throw new \LogicException('A Printess order reproduction is already in progress.');
        }
        if ($originalOrderId <= 0) {
            throw new \InvalidArgumentException('originalOrderId must be > 0');
        }
        $this->originalOrderId = $originalOrderId;
        $this->message = trim($message);
        $this->shippingDescription = trim($shippingDescription);
        $this->discountEnabled = true;
        $this->pendingOrderData = $pendingOrderData;
    }

    public function stop(): void
    {
        $this->originalOrderId = null;
        $this->message = '';
        $this->shippingDescription = '';
        $this->discountEnabled = true;
        $this->pendingOrderData = [];
    }

    public function isActive(): bool
    {
        return $this->originalOrderId !== null;
    }

    /**
     * Active AND the given quote flag (`quote.printess_reproduction_of`) names the order being copied.
     */
    public function isActiveFor(mixed $quoteReproductionOf): bool
    {
        if ($this->originalOrderId === null || $quoteReproductionOf === null || $quoteReproductionOf === '') {
            return false;
        }
        if (!is_int($quoteReproductionOf) && !(is_string($quoteReproductionOf) && ctype_digit($quoteReproductionOf))) {
            return false;
        }
        return (int) $quoteReproductionOf === $this->originalOrderId;
    }

    public function getOriginalOrderId(): ?int
    {
        return $this->originalOrderId;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getShippingDescription(): string
    {
        return $this->shippingDescription;
    }

    /** False on the Option C fallback (items at custom price 0): the collector then stays out of the way. */
    public function isDiscountEnabled(): bool
    {
        return $this->originalOrderId !== null && $this->discountEnabled;
    }

    public function setDiscountEnabled(bool $enabled): void
    {
        $this->discountEnabled = $enabled;
    }

    /** @return array<string, mixed> */
    public function getPendingOrderData(): array
    {
        return $this->originalOrderId !== null ? $this->pendingOrderData : [];
    }

    /** @param array<string, mixed> $data */
    public function setPendingOrderData(array $data): void
    {
        $this->pendingOrderData = $data;
    }
}
