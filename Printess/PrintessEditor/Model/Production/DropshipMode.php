<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Model\Production;

/**
 * Dropship mode values of the `printess_dropship_mode` product attribute. Dropshipping is
 * configured per product only; there is no store-level default.
 *
 * PURE: no Magento dependencies, so resolvers and tests can use it directly.
 */
final class DropshipMode
{
    /** No dropshipping: the item is produced as an ordinary print PDF. */
    public const NONE = 'none';

    /** Dropship with the explicitly configured product definition. */
    public const DROPSHIPPING = 'dropshipping';

    /** Dropship with the product definition carried by the Printess template (productDefinitionId 0). */
    public const TEMPLATE = 'template';

    /**
     * Legacy value ("Use store setting") from before dropshipping became product-only. Treated like an
     * empty value (not set). Cleared from stored products by the RemoveStoreDropshipSettings patch.
     */
    public const USE_DEFAULT = 'use_default';

    private function __construct()
    {
    }
}
