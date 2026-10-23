<?php
// Run from the Magento root: php app/code/Printess/PrintessEditor/Test/Regression/cart-edit-options.php
declare(strict_types=1);

require dirname(__DIR__, 6) . '/vendor/autoload.php';

use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;
use Printess\PrintessEditor\Controller\Cart\Update;
use Printess\PrintessEditor\Model\Product\FormFieldValues;

function check($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . json_encode($actual));
    }
}

$serializer = new Json();
$small = new DataObject(['option_type_id' => '10', 'title' => 'Small', 'price' => 0]);
$large = new DataObject(['option_type_id' => '11', 'title' => 'Large', 'price' => 9]);
$option = new DataObject([
    'type' => 'drop_down', 'title' => 'Document Size', 'option_id' => '7', 'values' => [$small, $large],
]);
$product = new class extends \Magento\Catalog\Model\Product {
    public function __construct() {}
    public function getFinalPrice($qty = null) { return $qty >= 5 ? 24.0 : 28.0; }
};
$product->setData(['type_id' => 'simple', 'options' => [$option]]);
$item = new class extends \Magento\Quote\Model\Quote\Item {
    public array $storedOptions = [];
    public function __construct() {}
    public function getOptionByCode($code) { return $this->storedOptions[$code] ?? null; }
    public function addOption($option) {
        $this->storedOptions[$option['code']] = new DataObject(['value' => $option['value']]);
        return $this;
    }
};
$item->setProductId(42);
$item->addOption(['code' => 'info_buyRequest', 'value' => '{"options":{"7":"10"}}']);

$class = new ReflectionClass(Update::class);
$controller = $class->newInstanceWithoutConstructor();
$class->getProperty('serializer')->setValue($controller, $serializer);
$class->getProperty('logger')->setValue($controller, new \Psr\Log\NullLogger());
$merge = $class->getMethod('mergeTrustedFormFields');
$update = $class->getMethod('updateItemCustomOptions');
$basePrice = $class->getMethod('computeOptionBasePrice');
$pagePrice = $class->getMethod('resolvePricePerPage');

check('Large', FormFieldValues::get(['DOCUMENT_SIZE' => 'Large'], ' Document Size '), 'Field aliases match');
check('', FormFieldValues::get(['DOCUMENT_SIZE' => ''], 'Document Size'), 'Empty API value stays authoritative');
check(null, FormFieldValues::get(['COVER_TYPE' => 'Hard'], 'Document Size'), 'Unrelated fields do not match');
check('Small', FormFieldValues::get(['DOCUMENT_SIZE' => 'Large', 'Document Size' => 'Small'], 'Document Size'), 'Exact name wins');

$fields = $merge->invoke($controller, $item, $product, ['DOCUMENT_SIZE' => 'Large']);
check('Large', $fields['Document Size'], 'API alias replaces the previous selection');
$update->invoke($controller, $item, $product, $fields);
check('11', $item->getOptionByCode('option_7')->getValue(), 'New option value persisted');
$request = $serializer->unserialize($item->getOptionByCode('info_buyRequest')->getValue());
check('11', $request['options']['7'], 'Buy request agrees with the item selection');
check(37.0, $basePrice->invoke($controller, $product, $fields, 1.0), 'New option charge included');
check(33.0, $basePrice->invoke($controller, $product, $fields, 5.0), 'Native quantity tier included');
check(37.0, $basePrice->invoke($controller, $product, ['DOCUMENT_SIZE' => 'Large']), 'Raw API alias prices consistently');
check(2.0, $pagePrice->invoke($controller, [
    ['conditions' => '', 'pricePerPage' => 1],
    ['conditions' => 'document size=Large', 'pricePerPage' => 2],
], ['DOCUMENT_SIZE' => 'Large']), 'Page conditions use the same field matching');

$fallback = $merge->invoke($controller, $item, $product, ['COVER_TYPE' => 'Hard']);
check('Large', $fallback['Document Size'], 'API-absent option retains its saved selection');
check(37.0, $basePrice->invoke($controller, $product, $fallback), 'Partial fields retain existing option charge');
$update->invoke($controller, $item, $product, ['document_size' => 'Small']);
check('10', $item->getOptionByCode('option_7')->getValue(), 'Repeated edit updates the selection');
check(28.0, $basePrice->invoke($controller, $product, ['document_size' => 'Small']), 'Repeated edit clears previous option charge');
echo "Passed: cart-edit aliases, API precedence, option persistence, partial fields, page conditions and quantity pricing.\n";
