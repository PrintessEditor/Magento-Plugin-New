<?php
// Run from Magento root: php app/code/Printess/PrintessEditor/Test/Regression/attribute-and-token.php
require dirname(__DIR__, 6) . '/vendor/autoload.php';

use Printess\PrintessEditor\Model\Product\EditorSettings;
use Printess\PrintessEditor\Model\SaveTokenStatus;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\FormFields;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\PagePricing;

function check($actual, $expected, string $message): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message . ': ' . json_encode($actual));
    }
}
check(EditorSettings::formFields('[{"fieldName":" SIZE ","fieldValue":"A4"},{"fieldName":"OLD","delete":true}]'),
    [['name' => 'SIZE', 'value' => 'A4']], 'Predefined fields converted and deleted rows omitted');

$request = new class extends \Magento\Framework\App\Request\Http {
    public array $testPost = [];
    public function __construct() {}
    public function getPostValue($name = null, $default = null) { return $this->testPost[$name] ?? $default; }
};
$state = new class extends \Magento\Framework\App\State {
    public function __construct() {}
    public function getAreaCode() { return 'adminhtml'; }
};
foreach ([FormFields::class => 'printess_form_fields', PagePricing::class => 'printess_page_pricing'] as $class => $code) {
    $backend = new $class($request, $state);
    $attribute = new \Magento\Framework\DataObject(['attribute_code' => $code]);
    $backend->setAttribute($attribute);
    $stored = $code === 'printess_form_fields' ? '[{"fieldName":"SIZE","fieldValue":"A4"}]'
        : '[{"conditions":"DOCUMENT_SIZE=Large","pricePerPage":1.5}]';
    $object = new \Magento\Framework\DataObject([$code => $stored]);
    $request->testPost = ['product' => ['name' => 'Unrelated edit']];
    $backend->beforeSave($object);
    check($object->getData($code) !== '[]' && $object->getData($code) !== null, true, 'Unrelated edit preserves value');
    $request->testPost = ['use_default' => [$code => 1]];
    $backend->beforeSave($object);
    check($object->getData($code), null, 'Use Default clears store override');
    $request->testPost = ['product' => [$code => []], 'use_default' => [$code => 0]];
    $backend->beforeSave($object);
    check($object->getData($code), '[]', 'Explicit empty value handled');
    $object->setData($code, $stored);
    $request->testPost = ['product' => [$code => $stored], 'use_default' => [$code => 0]];
    $backend->beforeSave($object);
    check(json_decode($object->getData($code), true), json_decode($stored, true), 'JSON and unchecked inheritance preserved');
    $object->setData($code, null);
    $request->testPost = ['product' => ['name' => 'Unrelated edit']];
    $backend->beforeSave($object);
    check($object->getData($code), null, 'Absent store override stays inherited');
    if ($code === 'printess_page_pricing') {
        $request->testPost = ['product' => [$code => [['conditions' => '', 'pricePerPage' => 1.5, 'delete' => true]]]];
        $backend->beforeSave($object);
        check($object->getData($code), '[]', 'Deleting all dynamic rows clears page pricing');
    }
}

// Save-token expiry (merged with the dropshipping branch): PrintessApi::getSaveTokenState() maps the
// /shop/template/info answer through the pure SaveTokenStatus. Only a definite answer counts as active; error
// payloads, malformed or past dates are never active. The endpoint/body contract is covered by the live
// API contract test (A3) and Test/Unit/Model/SaveTokenStatusTest.
foreach ([[], ['c' => 101, 'm' => 'Error'], ['payload' => []], ['active' => true],
    ['expiresOn' => '1999-01-01T00:00:00Z'], ['expiresOn' => 'bad-date'], ['expiresOn' => '']] as $response) {
    check(SaveTokenStatus::fromInfo($response)['state'] === SaveTokenStatus::ACTIVE, false, 'Error/malformed/expired response rejected');
}
check(SaveTokenStatus::fromInfo(null)['state'], SaveTokenStatus::INACTIVE, '404 (token not found) is inactive');
check(SaveTokenStatus::fromInfo(['expiresOn' => null])['state'], SaveTokenStatus::ACTIVE, 'Never-expiring token accepted');
check(SaveTokenStatus::fromInfo(['expiresOn' => gmdate('c', time() + 3600)])['state'], SaveTokenStatus::ACTIVE, 'Future expiration accepted');
echo "Passed: field conversion, safe partial saves, store inheritance and strict token expiry.\n";

// Exercise the registered modifiers against the same container shape Magento EAV builds.
if (!function_exists('__')) { function __($text) { return $text; } }
$url = new class extends \Magento\Backend\Model\Url {
    public function __construct() {}
    public function getUrl($routePath = null, $routeParams = null) { return '/admin/' . $routePath; }
};
$manager = new \Magento\Framework\Stdlib\ArrayManager();
$meta = ['printess' => ['children' => []]];
foreach (['printess_template', 'printess_merge_template', 'printess_form_fields'] as $code) {
    $meta['printess']['children']['container_' . $code]['children'][$code]['arguments']['data']['config'] = [
        'service' => ['template' => 'ui/form/element/helper/service'], 'disabled' => true
    ];
}
$templateModifier = new \Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier\PrintessTemplateModifier($manager, $url);
$fieldsModifier = new \Printess\PrintessEditor\Ui\DataProvider\Product\Form\Modifier\PrintessFormFieldsModifier($manager, $url);
$meta = $fieldsModifier->modifyMeta($templateModifier->modifyMeta($meta));
$templateConfig = $meta['printess']['children']['container_printess_template']['children']['printess_template']['arguments']['data']['config'];
check($templateConfig['endpointTemplates'], '/admin/printess/api/templates', 'Picker endpoint supplied');
$fieldsConfig = $meta['printess']['children']['container_printess_form_fields']['children']['printess_form_fields']['arguments']['data']['config'];
check($fieldsConfig['component'], 'Printess_PrintessEditor/js/product/form/element/form-fields-editor', 'Predefined field editor configured');
$di = simplexml_load_file(dirname(__DIR__, 2) . '/etc/adminhtml/di.xml');
foreach (['PrintessTemplateModifier', 'PrintessFormFieldsModifier'] as $name) {
    check(str_contains($di->asXML(), '\\' . $name), true, 'Admin modifier registered');
}
echo "Passed: admin picker and field editor.\n";
