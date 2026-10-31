<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\QuoteRequest\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Zero\QuoteRequest\Model\OrderGuard;

/**
 * checkout_submit_before: a cart whose quote can't be ordered is refused
 * before anything is spent on it (gift cards, reward points). Read-only;
 * the claim happens when the order is placed (Plugin\ClaimQuoteOnPlace).
 */
class CheckQuoteBeforeSubmit implements ObserverInterface
{
    public function __construct(private readonly OrderGuard $guard)
    {
    }

    public function execute(Observer $observer): void
    {
        $quote = $observer->getEvent()->getData('quote');
        if ($quote instanceof Quote) {
            $this->guard->check($quote);
        }
    }
}
