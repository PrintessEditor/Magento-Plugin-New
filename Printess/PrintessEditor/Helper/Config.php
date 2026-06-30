<?php
namespace Printess\PrintessEditor\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config extends AbstractHelper
{
    public const XML_PATH_SHOP_TOKEN     = 'printess_editor/api/shop_token';
    public const XML_PATH_SERVICE_TOKEN  = 'printess_editor/api/service_token';
    public const XML_PATH_EDITOR_THEME   = 'printess_editor/editor/theme';
    public const XML_PATH_PRINT_SETTINGS = 'printess_editor/production/print_settings';

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
}
