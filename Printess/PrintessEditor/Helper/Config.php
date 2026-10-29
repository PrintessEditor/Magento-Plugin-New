<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\Production\CallbackUrlPolicy;
use Printess\PrintessEditor\Model\Production\DropshipMode;

class Config extends AbstractHelper
{
    public const XML_PATH_SHOP_TOKEN     = 'printess_designer/api_token/shop_token';
    public const XML_PATH_SERVICE_TOKEN  = 'printess_designer/api_token/service_token';
    public const XML_PATH_EDITOR_THEME   = 'printess_designer/editor/theme';
    public const XML_PATH_EDITOR_VERSION = 'printess_designer/editor/editor_version';
    public const XML_PATH_PRINT_SETTINGS = 'printess_designer/production/print_settings';

    public const XML_PATH_API_URL                     = 'printess_designer/api_token/api_url';
    public const XML_PATH_HOLD_UNTIL_PAYMENT          = 'printess_designer/production/hold_until_payment';
    public const XML_PATH_APPROVE_BEFORE_PRODUCTION   = 'printess_designer/production/approve_before_production';
    public const XML_PATH_PRODUCTION_CALLBACK_URL     = 'printess_designer/production/production_callback_url';
    public const XML_PATH_PRODUCTION_CALLBACK_MODE    = 'printess_designer/production/callback_mode';
    public const XML_PATH_SEND_SHIPMENT_EMAIL         = 'printess_designer/production/send_shipment_email';
    public const XML_PATH_DROPSHIP_REPRODUCTION_MODE  = 'printess_designer/production/dropship_reproduction_mode';

    /** Restarting a dropship group: send again with `-R<n>` (default) or ask (send again / copy the order, PMD-21). */
    public const REPRODUCTION_MODE_SUFFIX = 'suffix';
    public const REPRODUCTION_MODE_ASK    = 'ask';

    /** "Production status updates" (PMD-26): Printess calls the shop back (default) or the shop polls. */
    public const CALLBACK_MODE_CALLBACKS = 'callbacks';
    public const CALLBACK_MODE_POLLING   = 'polling';

    public const DEFAULT_API_URL = 'https://api.printess.com';

    private const EDITOR_HOST = 'https://editor.printess.com/';

    public const XML_PATH_LOGIN_NEW_CUSTOMER_HEADING  = 'printess_designer/login_popup/new_customer_heading';
    public const XML_PATH_LOGIN_CUSTOMER_LOGIN_HEADING = 'printess_designer/login_popup/customer_login_heading';

    private EncryptorInterface $encryptor;
    private StoreManagerInterface $storeManager;
    private CallbackUrlPolicy $callbackUrlPolicy;

    /** Store keys already warned about an unusable callback URL in this request (log once per request). */
    private array $callbackUrlWarned = [];

    public function __construct(
        Context $context,
        EncryptorInterface $encryptor,
        StoreManagerInterface $storeManager,
        CallbackUrlPolicy $callbackUrlPolicy
    ) {
        parent::__construct($context);
        $this->encryptor = $encryptor;
        $this->storeManager = $storeManager;
        $this->callbackUrlPolicy = $callbackUrlPolicy;
    }

    /**
     * All getters take an optional store id. Null means "current store", which is right on the
     * frontend; cron, callback and admin paths must pass the order's store id explicitly, because
     * outside a request the current store silently falls back to the default store view.
     */
    public function getShopToken(?int $storeId = null): string
    {
        $value = $this->getStoreValue(self::XML_PATH_SHOP_TOKEN, $storeId);
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    public function getServiceToken(?int $storeId = null): string
    {
        $value = $this->getStoreValue(self::XML_PATH_SERVICE_TOKEN, $storeId);
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    public function getEditorTheme(?int $storeId = null): string
    {
        return $this->getStoreValue(self::XML_PATH_EDITOR_THEME, $storeId);
    }

    public function getPrintSettings(?int $storeId = null): string
    {
        return $this->getStoreValue(self::XML_PATH_PRINT_SETTINGS, $storeId);
    }

    /**
     * The raw store-level "Editor Version" setting, e.g. "nightly" or "v/nightly", exactly
     * as the merchant typed it (just whitespace-trimmed). Empty means "load the current
     * release version". Most callers want getPanelLoaderUrl()/getSlimLoaderUrl() instead of
     * this directly.
     */
    public function getEditorVersion(?int $storeId = null): string
    {
        return trim($this->getStoreValue(self::XML_PATH_EDITOR_VERSION, $storeId));
    }

    /**
     * The "v/<version>/" path segment to insert between the Printess editor host and the
     * loader script name, or '' to load the current release (no /v/ segment at all).
     * Automatically prefixes "v/" onto the configured value when the merchant didn't
     * already type it, so both "nightly" and "v/nightly" resolve to the same segment.
     */
    public function getEditorVersionPath(?int $storeId = null): string
    {
        $version = trim($this->getEditorVersion($storeId), " \t\n\r\0\x0B/");
        if ($version === '') {
            return '';
        }

        if (strtolower(substr($version, 0, 2)) !== 'v/') {
            $version = 'v/' . $version;
        }

        return $version . '/';
    }

    /**
     * Full URL of the Panel (full-screen) editor loader script, honouring the configured
     * editor version, e.g. "https://editor.printess.com/v/nightly/printess-editor/loader.js"
     * or, with no version configured, "https://editor.printess.com/printess-editor/loader.js".
     */
    public function getPanelLoaderUrl(?int $storeId = null): string
    {
        return self::EDITOR_HOST . $this->getEditorVersionPath($storeId) . 'printess-editor/loader.js';
    }

    /**
     * Full URL of the Slim UI loader script, honouring the configured editor version, e.g.
     * "https://editor.printess.com/v/nightly/slim-ui.js" or, with no version configured,
     * "https://editor.printess.com/slim-ui.js".
     */
    public function getSlimLoaderUrl(?int $storeId = null): string
    {
        return self::EDITOR_HOST . $this->getEditorVersionPath($storeId) . 'slim-ui.js';
    }

    /**
     * Printess API base URL without trailing slash. Blank config means the public production API;
     * the setting only exists so staging/test accounts can point elsewhere.
     */
    public function getApiUrl(?int $storeId = null): string
    {
        $url = rtrim(trim($this->getStoreValue(self::XML_PATH_API_URL, $storeId)), '/');
        return $url !== '' ? $url : self::DEFAULT_API_URL;
    }

    /** Wait for the item to be invoiced before sending it to production (all Printess items). */
    public function isHoldUntilPayment(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_HOLD_UNTIL_PAYMENT, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** Require an admin to approve each job before it is sent to production. */
    public function isApproveBeforeProduction(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_APPROVE_BEFORE_PRODUCTION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /** 'callbacks' (default) or 'polling'; anything else counts as 'callbacks'. */
    public function getProductionCallbackMode(?int $storeId = null): string
    {
        $mode = trim($this->getStoreValue(self::XML_PATH_PRODUCTION_CALLBACK_MODE, $storeId));
        return $mode === self::CALLBACK_MODE_POLLING ? self::CALLBACK_MODE_POLLING : self::CALLBACK_MODE_CALLBACKS;
    }

    /**
     * Base URL Printess should call back, with exactly one trailing '/', or '' to poll instead (PMD-26):
     * - '' in polling mode, whatever URL is set;
     * - the "Callback URL" override when set (for debugging, e.g. a tunnel to a local shop);
     * - otherwise the store view's own secure base URL.
     * A URL Printess would reject (not https, IP, localhost, *.test, *.local …) yields '' with a warning logged
     * once per request, because submitting it would fail production with 406 c102. Callers treat '' as "poll".
     */
    public function getProductionCallbackBaseUrl(?int $storeId = null): string
    {
        if ($this->getProductionCallbackMode($storeId) === self::CALLBACK_MODE_POLLING) {
            return '';
        }

        $override = trim($this->getStoreValue(self::XML_PATH_PRODUCTION_CALLBACK_URL, $storeId));
        if ($override !== '') {
            $url = $this->callbackUrlPolicy->normalize($override);
            $source = 'Callback URL override';
        } else {
            try {
                $base = (string)$this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_WEB, true);
            } catch (\Throwable $e) {
                $base = '';
            }
            $url = $base !== '' ? rtrim(trim($base), '/') . '/' : '';
            $source = 'store base URL';
        }

        if ($url !== '' && $this->callbackUrlPolicy->isUsable($url)) {
            return $url;
        }

        $this->warnUnusableCallbackUrl($storeId, $override !== '' ? $override : $url, $source);
        return '';
    }

    private function warnUnusableCallbackUrl(?int $storeId, string $url, string $source): void
    {
        $key = $storeId === null ? 'current' : (string)$storeId;
        if (isset($this->callbackUrlWarned[$key])) {
            return;
        }
        $this->callbackUrlWarned[$key] = true;
        $this->_logger->warning(
            'Printess: production callbacks are not possible because the ' . $source . ' cannot be reached by '
            . 'Printess (needs a public https domain name); falling back to polling.',
            ['store_id' => $storeId, 'url' => $url]
        );
    }

    /** Notify the customer when a dropship shipment is created. Defaults to yes (see config.xml). */
    public function isSendShipmentEmail(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SEND_SHIPMENT_EMAIL, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /** 'suffix' (default) or 'ask'; anything else counts as 'suffix'. */
    public function getDropshipReproductionMode(?int $storeId = null): string
    {
        $mode = trim($this->getStoreValue(self::XML_PATH_DROPSHIP_REPRODUCTION_MODE, $storeId));
        return $mode === self::REPRODUCTION_MODE_ASK ? self::REPRODUCTION_MODE_ASK : self::REPRODUCTION_MODE_SUFFIX;
    }

    private function getStoreValue(string $path, ?int $storeId): string
    {
        return (string)$this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getLoginNewCustomerHeading(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_LOGIN_NEW_CUSTOMER_HEADING, ScopeInterface::SCOPE_STORE);
    }

    public function getLoginCustomerLoginHeading(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_LOGIN_CUSTOMER_LOGIN_HEADING, ScopeInterface::SCOPE_STORE);
    }
}
