<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * PURE. The order number a dropshipper receives (`dropship.data.shopOrderId`) and the send counter behind it.
 *
 * A Magento order has one shipping address, so it has exactly one dropship group: send 1 uses the increment id,
 * every later send (restart) appends `-R1`, `-R2`, ... because dropshippers like Siteflow refuse an order number
 * they have seen before (Shopware `dropshipShopOrderId()`).
 */
final class DropshipShopOrderId
{
    public static function build(string $incrementId, int $sendNumber): string
    {
        $incrementId = trim($incrementId);
        return $sendNumber > 1 ? $incrementId . '-R' . ($sendNumber - 1) : $incrementId;
    }

    /**
     * The number of the next send of a group: 1 + the highest send count of its items (0 = never sent).
     *
     * @param array<int|string|null> $sendCounts the `dropship_send_count` values of the group's jobs
     */
    public static function nextSendNumber(array $sendCounts): int
    {
        $max = 0;
        foreach ($sendCounts as $count) {
            if (is_numeric($count)) {
                $max = max($max, (int) $count);
            }
        }
        return $max + 1;
    }
}
