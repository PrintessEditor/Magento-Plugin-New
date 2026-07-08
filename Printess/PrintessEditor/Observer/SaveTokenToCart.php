<?php

declare(strict_types=1);

namespace Printess\PrintessEditor\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\SerializerInterface;

class SaveTokenToCart implements ObserverInterface
{
    private RequestInterface $request;
    private SerializerInterface $serializer;

    public function __construct(RequestInterface $request, SerializerInterface $serializer)
    {
        $this->request    = $request;
        $this->serializer = $serializer;
    }

    public function execute(Observer $observer): void
    {
        $params = $this->getParams();

        if (empty($params['saveToken'])) {
            return;
        }

        $item = $observer->getQuoteItem();
        $item = $item->getParentItem() ?: $item;

        $additionalOptions = [];
        if ($existing = $item->getOptionByCode('additional_options')) {
            $additionalOptions = $this->serializer->unserialize($existing->getValue());
        }

        $additionalOptions['printess_save_token'] = [
            'label' => 'Printess Save Token',
            'value' => $params['saveToken'],
        ];

        if (!empty($params['thumbnailUrl'])) {
            $additionalOptions['printess_thumbnail_url'] = [
                'label' => 'Printess Thumbnail',
                'value' => $params['thumbnailUrl'],
            ];
        }

        $item->addOption([
            'product_id' => $item->getProductId(),
            'code'       => 'additional_options',
            'value'      => $this->serializer->serialize($additionalOptions),
        ]);

        $pageCount = (int)($params['printessPageCount'] ?? 0);

        if ($pageCount > 0) {
            $formFields  = json_decode((string)($params['printessFormFields'] ?? '{}'), true) ?: [];
            $pagePricing = $item->getProduct()->getData('printess_page_pricing');
            if (is_string($pagePricing)) {
                $pagePricing = json_decode($pagePricing, true) ?: [];
            }
            $pagePricing = is_array($pagePricing) ? $pagePricing : [];

            $pricePerPage = $this->resolvePricePerPage($pagePricing, $formFields);

            if ($pricePerPage > 0) {
                $customPrice = (float)$item->getProduct()->getFinalPrice() + $pageCount * $pricePerPage;
                $item->setCustomPrice($customPrice);
                $item->setOriginalCustomPrice($customPrice);
                $item->getProduct()->setIsSuperMode(true);
            }
        }
    }

    private function resolvePricePerPage(array $rules, array $formFields): float
    {
        $bestPrice = 0.0;
        $bestScore = -1;

        foreach ($rules as $rule) {
            $conditions = trim((string)($rule['conditions'] ?? ''));
            $price      = (float)($rule['pricePerPage'] ?? 0);

            if ($conditions === '') {
                if ($bestScore < 0) { $bestScore = 0; $bestPrice = $price; }
                continue;
            }

            $parts    = explode(',', $conditions);
            $score    = 0;
            $allMatch = true;

            foreach ($parts as $part) {
                $kv = explode('=', $part, 2);
                if (count($kv) !== 2) { continue; }
                $key     = trim($kv[0]);
                $val     = strtolower(trim($kv[1]));
                $current = strtolower((string)($formFields[$key] ?? ''));
                if ($current !== $val) { $allMatch = false; break; }
                $score++;
            }

            if ($allMatch && $score > $bestScore) { $bestScore = $score; $bestPrice = $price; }
        }

        return $bestScore >= 0 ? $bestPrice : 0.0;
    }

    private function getParams(): array
    {
        $content = $this->request->getContent();
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $this->request->getPostValue() ?: [];
    }
}
