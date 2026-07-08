<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Cart;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;

/**
 * Updates the Printess save token on an existing cart item without removing/re-adding it.
 * Called by the JS editor integration after the customer re-customises from the cart.
 *
 * POST /printess/cart/update
 *   itemId       int     Quote item ID
 *   saveToken    string  New Printess save token
 *   thumbnailUrl string  New thumbnail URL (optional)
 *   form_key     string  Magento form key
 */
class Update implements HttpPostActionInterface
{
    private RequestInterface $request;
    private JsonFactory $jsonFactory;
    private Validator $formKeyValidator;
    private CheckoutSession $checkoutSession;
    private SerializerInterface $serializer;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        Validator $formKeyValidator,
        CheckoutSession $checkoutSession,
        SerializerInterface $serializer
    ) {
        $this->request          = $request;
        $this->jsonFactory      = $jsonFactory;
        $this->formKeyValidator = $formKeyValidator;
        $this->checkoutSession  = $checkoutSession;
        $this->serializer       = $serializer;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->formKeyValidator->validate($this->request)) {
            return $result->setData(['success' => false, 'message' => 'Invalid form key']);
        }

        $itemId       = (int)$this->request->getParam('itemId');
        $saveToken    = (string)$this->request->getParam('saveToken');
        $thumbnailUrl = (string)$this->request->getParam('thumbnailUrl', '');

        if (!$itemId || !$saveToken) {
            return $result->setData(['success' => false, 'message' => 'Missing required parameters']);
        }

        try {
            $quote = $this->checkoutSession->getQuote();
            $item  = $quote->getItemById($itemId);

            if (!$item) {
                return $result->setData(['success' => false, 'message' => 'Cart item not found']);
            }

            $additionalOptions = [];
            if ($existing = $item->getOptionByCode('additional_options')) {
                $additionalOptions = $this->serializer->unserialize($existing->getValue());
            }

            $additionalOptions['printess_save_token'] = [
                'label' => 'Printess Save Token',
                'value' => $saveToken,
            ];

            if ($thumbnailUrl !== '') {
                $additionalOptions['printess_thumbnail_url'] = [
                    'label' => 'Printess Thumbnail',
                    'value' => $thumbnailUrl,
                ];
            }

            $item->addOption([
                'product_id' => $item->getProductId(),
                'code'       => 'additional_options',
                'value'      => $this->serializer->serialize($additionalOptions),
            ]);

            $quote->save();

            return $result->setData(['success' => true]);
        } catch (\Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}
