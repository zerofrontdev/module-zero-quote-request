<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Model;

use Magento\Catalog\Helper\Product\ConfigurationPool;
use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;

/**
 * A request's lines: from a cart (what is in it, with its options), or from
 * one product page (its add-to-cart form's fields).
 */
class Lines
{
    /** Request fields that are about the page, not the product. */
    private const NOT_PRODUCT = ['form_key', 'uenc', 'return_url', 'related_product', 'original_qty', 'custom_price', 'name', 'email', 'phone', 'company', 'comment', 'source'];

    public function __construct(private readonly ConfigurationPool $configurationPool)
    {
    }

    /**
     * @return array<int, array>
     */
    public function fromCart(Quote $quote): array
    {
        $lines = [];
        foreach ($quote->getAllVisibleItems() as $item) {
            $options = [];
            try {
                foreach ($this->configurationPool->getByProductType($item->getProductType())->getOptions($item) as $option) {
                    $value = $option['print_value'] ?? $option['value'] ?? '';
                    $options[] = ['label' => (string) $option['label'], 'value' => strip_tags(is_array($value) ? implode(', ', $value) : (string) $value)];
                }
            } catch (\Throwable) {
                $options = [];
            }
            $lines[] = [
                'product_id' => (int) $item->getProduct()->getId(),
                'sku' => (string) $item->getProduct()->getData('sku'),
                'name' => (string) $item->getName(),
                'options' => $options,
                'buy_request' => self::clean($item->getBuyRequest()->getData()),
                'qty' => (float) $item->getQty(),
                'price' => (float) $item->getCalculationPrice(),
            ];
        }

        return $lines;
    }

    /**
     * @param array $post The product form's fields
     */
    public function fromProduct(Product $product, array $post): array
    {
        $request = self::clean($post);
        $request['qty'] = max(1.0, (float) ($post['qty'] ?? 1));
        $options = [];
        if ($product->getTypeId() === 'configurable' && !empty($request['super_attribute']) && is_array($request['super_attribute'])) {
            foreach ($product->getTypeInstance()->getConfigurableAttributesAsArray($product) as $attribute) {
                $chosen = $request['super_attribute'][$attribute['attribute_id']] ?? '';
                // An array (super_attribute[93][]=x) counts as not chosen.
                $chosen = is_scalar($chosen) ? (string) $chosen : '';
                foreach ($attribute['values'] as $value) {
                    if ((string) $value['value_index'] === $chosen) {
                        $options[] = ['label' => (string) $attribute['label'], 'value' => (string) $value['label']];
                    }
                }
            }
        }

        return [[
            'product_id' => (int) $product->getId(),
            'sku' => (string) $product->getSku(),
            'name' => (string) $product->getName(),
            'options' => $options,
            'buy_request' => $request,
            'qty' => $request['qty'],
            'price' => null,
        ]];
    }

    private static function clean(array $request): array
    {
        return array_diff_key($request, array_flip(self::NOT_PRODUCT));
    }

    public static function asDataObject(array $line, float $qty): DataObject
    {
        return new DataObject(['qty' => $qty] + $line['buy_request']);
    }
}
