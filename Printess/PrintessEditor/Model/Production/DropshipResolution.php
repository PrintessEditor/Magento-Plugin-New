<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Decides the Printess productDefinitionId for one order item from its product levels. Dropshipping is a
 * product setting only; there is no store-level default.
 *
 * PURE: plain arrays/scalars only, so the precedence rules can be unit-tested without Magento.
 *
 * Return value follows the Printess API semantics:
 *  - null  = no dropshipping (ordinary print job)
 *  - 0     = dropship, product definition taken from the Printess template
 *  - > 0   = dropship with this explicit product definition
 *
 * Product levels are walked most specific first (child simple → parent); the first level with a
 * NON-EMPTY mode decides (D14):
 *
 * | Level mode                       | Result                                                 |
 * |----------------------------------|--------------------------------------------------------|
 * | empty / null / unknown           | not a decision, go to the next level                   |
 * | use_default (legacy)             | treated like empty                                     |
 * | none                             | null                                                   |
 * | dropshipping                     | the level's definition id; no usable id → null         |
 * | template                         | 0                                                      |
 * | (all levels empty / no levels)   | null (not dropshipped)                                 |
 */
class DropshipResolution
{
    /**
     * @param array<int, array{mode?: ?string, definition_id?: ?int}> $levels Ordered most specific first
     *        (configurable child simple, then parent product).
     */
    public function resolve(array $levels): ?int
    {
        foreach ($levels as $level) {
            $mode = $this->normalizeMode($level['mode'] ?? null);
            if ($mode === null) {
                // Empty/unknown: not set on this level, the next (less specific) level decides.
                continue;
            }
            // The first level with an explicit choice is final. In particular an explicit
            // 'dropshipping' level without a usable definition id yields null rather than falling
            // through: the merchant deliberately overrode the lower levels here, and silently
            // dropshipping with the parent's definition (a different physical product)
            // would be worse than producing a normal print job.
            return $this->apply($mode, $level['definition_id'] ?? null);
        }

        // No level has a setting: a product without a dropship setting is not dropshipped (D14).
        return null;
    }

    private function apply(string $mode, ?int $definitionId): ?int
    {
        switch ($mode) {
            case DropshipMode::TEMPLATE:
                return 0;
            case DropshipMode::DROPSHIPPING:
                return ($definitionId !== null && $definitionId > 0) ? $definitionId : null;
            default: // DropshipMode::NONE
                return null;
        }
    }

    /** Returns the trimmed known mode, or null for empty, legacy use_default and unknown values (= not set). */
    private function normalizeMode(?string $mode): ?string
    {
        $mode = trim((string)$mode);
        if (in_array(
            $mode,
            [DropshipMode::NONE, DropshipMode::DROPSHIPPING, DropshipMode::TEMPLATE],
            true
        )) {
            return $mode;
        }
        return null;
    }
}
