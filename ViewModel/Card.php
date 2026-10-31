<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Zero\QuoteRequest\Model\Config;

/**
 * A product card's call to action for a Price on Request product (the
 * zero.quote.card block): instead of Add to Cart, which Observer\GuardQuoteOnly
 * would refuse, "Request a Price" links to the product page's request form.
 * Same rule as the guard and the "Price on request" price box.
 */
class Card implements ArgumentInterface
{
    /** The product page's request form (templates/product.phtml). */
    public const FORM_ID = 'zero-quote-product';

    public function __construct(
        private readonly Config $config,
        private readonly ProductResource $productResource,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function isQuoteOnly(mixed $product): bool
    {
        return $this->config->isQuoteOnly($product);
    }

    public function url(Product $product): string
    {
        return self::formUrl((string) $product->getProductUrl());
    }

    /**
     * A product page URL pointed at its request form.
     */
    public static function formUrl(string $productUrl): string
    {
        return explode('#', $productUrl, 2)[0] . '#' . self::FORM_ID;
    }

    /**
     * Which of these product ids are Price on Request in the current store,
     * for lists that have ids and no product objects (the "Recently Ordered"
     * sidebar: a last order's few items, one attribute read each).
     *
     * @param array<int|string> $productIds
     * @return int[]
     */
    public function quoteOnlyIds(array $productIds): array
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId)) {
            return [];
        }
        $ids = [];
        foreach (array_unique(array_map('intval', $productIds)) as $productId) {
            if ($productId > 0
                && (bool) $this->productResource->getAttributeRawValue($productId, Config::QUOTE_ONLY_ATTRIBUTE, $storeId)
            ) {
                $ids[] = $productId;
            }
        }

        return $ids;
    }
}
