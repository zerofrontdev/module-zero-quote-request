<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Zero\QuoteRequest\Model\Requests;

/**
 * zero_checkout_quote_restored (Zero_Checkout: the cart of a still-unpaid
 * redirect order made active again, `order` and `quote`): the customer can
 * pay again, so the quote that order claimed is quoted again (if not past
 * its date) and the next order can claim it. Only the claim of THAT order is
 * touched (matched by its number), so the event may repeat (GraphQL return
 * polling) and an order that was paid meanwhile keeps its quote.
 */
class ReleaseOnQuoteRestored implements ObserverInterface
{
    private const EPSILON = 0.0001;

    public function __construct(private readonly Requests $requests)
    {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface || (float) $order->getBaseTotalPaid() > self::EPSILON) {
            return;
        }
        $this->requests->reopen((string) $order->getIncrementId());
    }
}
