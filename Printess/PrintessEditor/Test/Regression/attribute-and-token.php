<?php
// Run from Magento root: php app/code/Printess/PrintessEditor/Test/Regression/attribute-and-token.php
require dirname(__DIR__, 6) . '/vendor/autoload.php';

use Printess\PrintessEditor\Model\Product\EditorSettings;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\Product\Attribute\Backend\FormFields;

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
foreach ([FormFields::class => 'printess_form_fields'] as $class => $code) {
    $backend = new $class($request, $state);
    $attribute = new \Magento\Framework\DataObject(['attribute_code' => $code]);
    $backend->setAttribute($attribute);
    $stored = $code === 'printess_form_fields' ? '[{"fieldName":"SIZE","fieldValue":"A4"}]' : 'Legacy';
    $object = new \Magento\Framework\DataObject([$code => $stored]);
    $request->testPost = ['product' => ['name' => 'Unrelated edit']];
    $backend->beforeSave($object);
    check($object->getData($code) !== '[]' && $object->getData($code) !== null, true, 'Unrelated edit preserves value');
    $request->testPost = ['use_default' => [$code => 1]];
    $backend->beforeSave($object);
    check($object->getData($code), null, 'Use Default clears store override');
    $request->testPost = ['product' => [$code => []], 'use_default' => [$code => 0]];
    $backend->beforeSave($object);
    check($object->getData($code), $code === 'printess_form_fields' ? '[]' : null, 'Explicit empty value handled');
}

$api = new PrintessApi('test');
$method = new ReflectionMethod($api, 'isTokenRecordActive');
foreach ([[], ['c' => 101, 'm' => 'Error'], ['payload' => []], ['active' => true],
    ['expiresOn' => '1999-01-01T00:00:00Z'], ['expiresOn' => 'bad-date'], ['expiresOn' => '']] as $response) {
    check($method->invoke($api, $response), false, 'Error/malformed/expired response rejected');
}
check($method->invoke($api, ['expiresOn' => null]), true, 'Never-expiring token accepted');
check($method->invoke($api, ['expiresOn' => gmdate('c', time() + 3600)]), true, 'Future expiration accepted');
echo "Passed: field conversion, safe partial saves, store inheritance and strict token expiry.\n";

// Read-only fake transport: prove the public API uses the documented path/body.
class TokenFixtureStream {
    public $context;
    public static array $response = [];
    public static string $url = '';
    public static array $payload = [];
    private string $data = '';
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$openedPath): bool {
        self::$url = $path;
        self::$payload = json_decode(stream_context_get_options($this->context)['http']['content'], true);
        $this->data = json_encode(self::$response);
        return true;
    }
    public function stream_read($count): string {
        $data = substr($this->data, $this->position, $count);
        $this->position += strlen($data);
        return $data;
    }
    public function stream_eof(): bool { return $this->position >= strlen($this->data); }
    public function stream_stat(): array { return []; }
}
stream_wrapper_register('tokenfixture', TokenFixtureStream::class);
$fixtureApi = new PrintessApi('test', 'tokenfixture://api');
TokenFixtureStream::$response = ['expiresOn' => null];
check($fixtureApi->isSaveTokenActive('st:test'), true, 'Documented metadata accepted through public API');
check(TokenFixtureStream::$url, 'tokenfixture://api/shop/template/info', 'Correct metadata endpoint');
check(TokenFixtureStream::$payload, ['id' => 'st:test'], 'Correct metadata request body');
TokenFixtureStream::$response = ['c' => 101, 'm' => 'Invalid token'];
check($fixtureApi->isSaveTokenActive('st:test'), false, 'Error metadata rejected through public API');
stream_wrapper_unregister('tokenfixture');
echo "Passed: public token API endpoint and request contract.\n";

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
