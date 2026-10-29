<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Builds the admin-only order comment written when production jobs could not be queued for order items
 * (D9: production problems are shown on the order, never silently swallowed). Pure: no Magento dependencies.
 */
final class EnqueueFailureNote
{
    /** Keeps one error message from flooding the comment. */
    private const MAX_ERROR_LENGTH = 300;

    /**
     * @param list<array{name: string, sku: string, error: string}> $failures
     */
    public static function build(array $failures): string
    {
        if ($failures === []) {
            return '';
        }

        $lines = [];
        foreach ($failures as $failure) {
            $label = trim($failure['name']) !== '' ? trim($failure['name']) : 'Item';
            $sku   = trim($failure['sku']);
            $lines[] = sprintf(
                '- "%s"%s: %s',
                $label,
                $sku !== '' ? ' (SKU ' . $sku . ')' : '',
                self::shorten(trim($failure['error']) !== '' ? trim($failure['error']) : 'unknown error')
            );
        }

        $head = count($failures) === 1
            ? 'Printess: production could not be queued for 1 item. No print or dropship job was created for it, so it will not be produced.'
            : sprintf(
                'Printess: production could not be queued for %d items. No print or dropship jobs were created for them, so they will not be produced.',
                count($failures)
            );

        return $head . "\n" . implode("\n", $lines)
            . "\nDetails are in var/log/system.log. Fix the cause and arrange production for these items manually.";
    }

    private static function shorten(string $error): string
    {
        $error = preg_replace('/\s+/', ' ', $error) ?? $error;
        return mb_strlen($error) > self::MAX_ERROR_LENGTH
            ? mb_substr($error, 0, self::MAX_ERROR_LENGTH - 1) . '…'
            : $error;
    }
}
