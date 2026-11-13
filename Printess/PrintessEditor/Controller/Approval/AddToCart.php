<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Controller\Approval;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart as CustomerCart;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Printess\PrintessEditor\Model\Approval\ApprovalService;
use Printess\PrintessEditor\Model\Approval\Workflow;
use Printess\PrintessEditor\Model\PrintessApi;
use Printess\PrintessEditor\Model\PrintessApiProvider;
use Psr\Log\LoggerInterface;

/**
 * SUP-13: "Add to cart" for an approved design: the approved save token with the options stored at submission
 * (buy_request_json) goes through the normal cart add, so SaveTokenToCart prices and records it as usual.
 *
 * POST: form_key, project_id
 */
class AddToCart extends AbstractApprovalAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        CustomerSession $customerSession,
        JsonFactory $jsonFactory,
        ApprovalService $approvalService,
        LoggerInterface $logger,
        private readonly CustomerCart $cart,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly PrintessApiProvider $printessApiProvider
    ) {
        parent::__construct($context, $customerSession, $jsonFactory, $approvalService, $logger);
    }

    public function execute()
    {
        return $this->jsonCall(function (): array {
            $projectId = (int) $this->getRequest()->getParam('project_id');
            $submission = $this->approvalService->getSubmitterSubmission($this->customerId(), $projectId);
            if ($submission->getStatus() !== Workflow::STATUS_APPROVED) {
                throw new LocalizedException(__('Only approved designs can be added to the cart.'));
            }
            $token = (string) $submission->getData('approved_save_token');
            if (!$this->isTokenActive($token, $submission->getStoreId())) {
                throw new LocalizedException(__(
                    'This design has expired at Printess and cannot be ordered any more. Please create a new design and submit it for approval.'
                ));
            }
            $storeId = (int) $this->storeManager->getStore()->getId();
            try {
                $product = $this->productRepository->getById((int) $submission->getData('product_id'), false, $storeId, true);
            } catch (\Throwable) {
                throw new LocalizedException(__('The product of this design is no longer available.'));
            }
            if (!$product->isSalable()) {
                throw new LocalizedException(__('The product of this design is currently not available.'));
            }

            $buyRequest = $submission->getBuyRequest();
            $params = [
                'product' => (int) $product->getId(),
                'qty'     => (float) ($buyRequest['qty'] ?? 1) ?: 1,
            ];
            foreach (['super_attribute', 'options'] as $group) {
                if (!empty($buyRequest[$group]) && is_array($buyRequest[$group])) {
                    $params[$group] = $buyRequest[$group];
                }
            }

            // SaveTokenToCart reads the design from the request (same as the editor's add-to-cart post).
            $request = $this->getRequest();
            if (method_exists($request, 'setPostValue')) {
                $request->setPostValue('saveToken', $token);
                $request->setPostValue('printessProjectId', (string) $projectId);
                $thumb = (string) ($submission->getData('supervisor_edited')
                    ? $submission->getData('supervisor_thumbnail_url') : '');
                if ($thumb === '') {
                    $thumb = (string) $this->projectThumbnail($projectId);
                }
                $request->setPostValue('thumbnailUrl', $thumb);
            }

            $this->cart->addProduct($product, new DataObject($params));
            $this->cart->save();

            return [
                'redirect' => $this->_url->getUrl('checkout/cart'),
                'message'  => (string) __('The approved design was added to your cart.'),
            ];
        });
    }

    private function isTokenActive(string $token, int $storeId): bool
    {
        try {
            if (!$this->printessApiProvider->hasServiceToken($storeId)) {
                return true; // cannot check
            }
            // Only a definite "inactive" blocks; an outage (unknown) must not.
            return $this->printessApiProvider->forStore($storeId)->getSaveTokenState($token)
                !== PrintessApi::TOKEN_STATE_INACTIVE;
        } catch (\Throwable) {
            return true;
        }
    }

    private function projectThumbnail(int $projectId): string
    {
        $events = [];
        try {
            $events = $this->approvalService->getEvents($this->approvalService->getSubmitterSubmission(
                $this->customerId(),
                $projectId
            ));
        } catch (\Throwable) {
            return '';
        }
        foreach (array_reverse($events) as $event) {
            if ($event->getData('thumbnail_url')) {
                return (string) $event->getData('thumbnail_url');
            }
        }
        return '';
    }
}
