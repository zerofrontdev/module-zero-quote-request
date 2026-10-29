<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;

/**
 * Accepting a quote: the cart becomes its lines, each at its quoted price and
 * quantity (they stay so), and the cart remembers the request (the order
 * marks it ordered).
 */
class Accept
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Requests $requests,
        private readonly Accepting $accepting
    ) {
    }

    /**
     * @throws LocalizedException
     */
    /**
     * @param Quote|null $quote the cart to fill; the checkout session's when null (the theme)
     */
    public function execute(array $request, ?Quote $quote = null): void
    {
        if (!Requests::isOpen($request)) {
            throw new LocalizedException(__('This quote can no longer be accepted.'));
        }
        $fromSession = $quote === null;
        $quote ??= $this->checkoutSession->getQuote();
        if ((int) $request['store_id'] !== (int) $quote->getStoreId()) {
            throw new LocalizedException(__('Open this quote in the store it was made for.'));
        }
        foreach ($quote->getAllVisibleItems() as $item) {
            $quote->removeItem($item->getId());
        }
        foreach ($this->requests->items((int) $request['request_id']) as $line) {
            if ($line['quoted_price'] === null) {
                continue;
            }
            $product = $this->productRepository->getById((int) $line['product_id'], false, (int) $quote->getStoreId(), true);
            $buyRequest = Lines::asDataObject($line, (float) $line['qty']);
            $buyRequest->setData('custom_price', (float) $line['quoted_price']);
            $this->accepting->start($request, $line);
            try {
                $result = $quote->addProduct($product, $buyRequest);
            } finally {
                $this->accepting->stop();
            }
            if (is_string($result)) {
                throw new LocalizedException(__('%1: %2', $line['name'], $result));
            }
        }
        $quote->setData('zero_quote_request_id', (int) $request['request_id']);
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->cartRepository->save($quote);
        if ($fromSession) {
            $this->checkoutSession->setQuoteId($quote->getId());
        }
    }
}
