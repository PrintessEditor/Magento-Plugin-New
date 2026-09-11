<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_SHOP_TOKEN     = 'printess_designer/api_token/shop_token';
    public const XML_PATH_SERVICE_TOKEN  = 'printess_designer/api_token/service_token';
    public const XML_PATH_EDITOR_THEME   = 'printess_designer/editor/theme';
    public const XML_PATH_EDITOR_VERSION = 'printess_designer/editor/editor_version';
    public const XML_PATH_PRINT_SETTINGS = 'printess_designer/production/print_settings';

    private const EDITOR_HOST = 'https://editor.printess.com/';

    private EncryptorInterface $encryptor;

    public function __construct(Context $context, EncryptorInterface $encryptor)
    {
        parent::__construct($context);
        $this->encryptor = $encryptor;
    }

    public function getShopToken(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_SHOP_TOKEN, ScopeInterface::SCOPE_STORE);
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    public function getServiceToken(): string
    {
        $value = (string)$this->scopeConfig->getValue(self::XML_PATH_SERVICE_TOKEN, ScopeInterface::SCOPE_STORE);
        return $value ? $this->encryptor->decrypt($value) : '';
    }

    public function getEditorTheme(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_EDITOR_THEME, ScopeInterface::SCOPE_STORE);
    }

    public function getPrintSettings(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_PRINT_SETTINGS, ScopeInterface::SCOPE_STORE);
    }

    /**
     * The raw store-level "Editor Version" setting, e.g. "nightly" or "v/nightly", exactly
     * as the merchant typed it (just whitespace-trimmed). Empty means "load the current
     * release version". Most callers want getPanelLoaderUrl()/getSlimLoaderUrl() instead of
     * this directly.
     */
    public function getEditorVersion(): string
    {
        return trim((string)$this->scopeConfig->getValue(self::XML_PATH_EDITOR_VERSION, ScopeInterface::SCOPE_STORE));
    }

    /**
     * The "v/<version>/" path segment to insert between the Printess editor host and the
     * loader script name, or '' to load the current release (no /v/ segment at all).
     * Automatically prefixes "v/" onto the configured value when the merchant didn't
     * already type it, so both "nightly" and "v/nightly" resolve to the same segment.
     */
    public function getEditorVersionPath(): string
    {
        $version = trim($this->getEditorVersion(), " \t\n\r\0\x0B/");
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
    public function getPanelLoaderUrl(): string
    {
        return self::EDITOR_HOST . $this->getEditorVersionPath() . 'printess-editor/loader.js';
    }

    /**
     * Full URL of the Slim UI loader script, honouring the configured editor version, e.g.
     * "https://editor.printess.com/v/nightly/slim-ui.js" or, with no version configured,
     * "https://editor.printess.com/slim-ui.js".
     */
    public function getSlimLoaderUrl(): string
    {
        return self::EDITOR_HOST . $this->getEditorVersionPath() . 'slim-ui.js';
    }
}
