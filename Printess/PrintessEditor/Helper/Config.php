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
    public const XML_PATH_PRINT_SETTINGS = 'printess_designer/production/print_settings';

    public const XML_PATH_NAME_PROMPT_ENABLED     = 'printess_designer/project_name/enabled';
    public const XML_PATH_NAME_PROMPT_TEXT        = 'printess_designer/project_name/prompt_text';
    public const XML_PATH_NAME_PROMPT_PLACEHOLDER = 'printess_designer/project_name/placeholder';
    public const XML_PATH_NAME_PROMPT_ACTION      = 'printess_designer/project_name/action_label';
    public const XML_PATH_DEBUG_LOGGING           = 'printess_designer/debug/enabled_logging';

    public const XML_PATH_LOGIN_NEW_CUSTOMER_HEADING  = 'printess_designer/login_popup/new_customer_heading';
    public const XML_PATH_LOGIN_CUSTOMER_LOGIN_HEADING = 'printess_designer/login_popup/customer_login_heading';

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

    public function isNamePromptEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_NAME_PROMPT_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    public function getNamePromptText(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_NAME_PROMPT_TEXT, ScopeInterface::SCOPE_STORE);
    }

    public function getNamePromptPlaceholder(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_NAME_PROMPT_PLACEHOLDER, ScopeInterface::SCOPE_STORE);
    }

    public function getNamePromptActionLabel(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_NAME_PROMPT_ACTION, ScopeInterface::SCOPE_STORE);
    }

    public function isDebugLoggingEnabled(): bool
    {
        return (bool)$this->scopeConfig->getValue(self::XML_PATH_DEBUG_LOGGING, ScopeInterface::SCOPE_STORE);
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
